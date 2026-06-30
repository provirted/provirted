<?php
namespace App\Os;

/**
 * System saturation / node-pressure calculator.
 *
 * This is a hardened PHP port of the `calc_system_saturation.sh` shell script.
 * It produces a single node-level saturation score (TOTAL_PRESSURE, lower = more
 * free capacity) by blending three independent pressure signals:
 *
 *     IO  > CPU  > Memory
 *
 * The weighting reflects that in a VPS host the storage subsystem is almost
 * always the first thing to saturate, the CPU scheduler is the secondary
 * constraint, and memory exhaustion is tertiary but still critical for
 * stability. The final score is intended to be ingested by an external VPS
 * placement scheduler so that new guests land on the least-saturated node.
 *
 * Unlike the shell original, this implementation does NOT shell out to
 * `mpstat`/`iostat`/`nproc`. Those tools merely format counters that the kernel
 * already exposes, and their column layout differs wildly between sysstat
 * versions (making positional `awk` parsing fragile). Instead we sample the
 * underlying kernel files directly:
 *
 *     /proc/stat        - aggregate CPU jiffy counters  (what mpstat reads)
 *     /proc/diskstats   - per-device IO counters        (what iostat reads)
 *     /proc/loadavg     - run-queue / runnable tasks
 *     /proc/meminfo     - MemTotal / MemAvailable
 *     /proc/cpuinfo     - core count and current MHz
 *     /sys/.../cpufreq  - per-core current / maximum MHz
 *
 * Reading the kernel files directly is also a large speed win: the original
 * script spawned `mpstat 1 1` (1s) plus `iostat -x 1 2` (2s) plus `nproc` plus
 * several `awk` subprocesses sequentially (~3-4s and a dozen forks). This port
 * uses a SINGLE shared sample window that covers both the CPU and IO counters
 * at once, so the whole collection costs one ~1s sleep and zero subprocesses.
 *
 * --- Kernel-version robustness -------------------------------------------
 * The kernel /proc layouts have changed over the years; every parser here is
 * written against the *stable* leading columns and tolerates the rest:
 *
 *   /proc/stat "cpu" line - trailing fields were added over time
 *       idle/iowait/irq/softirq (2.6.0), steal (2.6.11), guest (2.6.24),
 *       guest_nice (2.6.33). Missing trailing fields simply read as 0.
 *   /proc/diskstats - the classic 14-column format gained discard stats in
 *       4.18 (18 cols) and flush stats in 5.5 (20 cols). Those are *appended*,
 *       so the columns we use (reads, read-ticks, writes, write-ticks,
 *       io-ticks) keep their indices on every kernel. Pre-2.6.25 "short"
 *       partition lines (7 cols) are ignored, as are partitions in general.
 *   /proc/meminfo MemAvailable - only exists since 3.14; on older kernels we
 *       reconstruct an equivalent estimate from MemFree/Buffers/Cached/Slab.
 *   /proc/cpuinfo "cpu MHz" - absent on some arches/VMs; we fall back to
 *       cpufreq sysfs, and core count falls back to counting /proc/stat cpuN
 *       lines if "processor" lines are unavailable.
 *
 * Every reader fails soft: a missing/unreadable kernel file yields a safe zero
 * rather than a fatal, so the cron job that calls this never dies.
 */
class Saturation {

	/**
	 * Seconds between the two counter snapshots. Matches the `1` interval used
	 * by `mpstat 1 1` and `iostat -x 1 2` in the original script. Long enough to
	 * smooth out instantaneous spikes, short enough to keep the cron run quick.
	 * Note: the *actual* elapsed time is measured with a monotonic clock and
	 * used for the rate maths, so a slightly-long sleep does not skew results.
	 */
	const SAMPLE_INTERVAL = 1;

	/**
	 * Block-device name prefixes to ignore when aggregating IO. These are pseudo
	 * or virtual devices that would pollute the storage-pressure signal:
	 *   loop = loopback mounts, ram = ramdisks, fd = floppy, sr = optical,
	 *   dm-  = device-mapper targets (their backing devices are already counted).
	 * Mirrors the `^(loop|ram|fd|sr|dm-)` filter in the shell script's awk block.
	 */
	const IO_IGNORE_PREFIXES = ['loop', 'ram', 'fd', 'sr', 'dm-'];

	/**
	 * Latency (ms) treated as "fully saturated" when normalising IO pressure.
	 * From the shell script: io = await/5.0 + util/100.0.
	 */
	const IO_AWAIT_FULL_MS = 5.0;

	/**
	 * Collect every saturation metric in one shot.
	 *
	 * Takes a paired CPU+disk snapshot, sleeps for $interval seconds, takes a
	 * second paired snapshot, then derives all rate-based and instantaneous
	 * metrics. The genuine elapsed time between snapshots is measured with a
	 * monotonic clock (hrtime) and used for the IO rate maths, which is more
	 * accurate than assuming the sleep was exactly $interval seconds.
	 *
	 * The returned array is flat and JSON-friendly so the caller can splice
	 * whichever keys it wants straight into the host-info payload.
	 *
	 * @param int $interval seconds to wait between samples (default SAMPLE_INTERVAL)
	 * @return array{
	 *     cores:int, cpu_mhz:float, cpu_mhz_max:float,
	 *     cpu_capacity:int, cpu_capacity_max:int,
	 *     cpu_idle:float, cpu_iowait:float, cpu_usage:float,
	 *     cpu_steal:float, cpu_steal_norm:float,
	 *     run_queue:int, run_queue_norm:float,
	 *     mem_total:int, mem_available:int,
	 *     io_pressure:float, cpu_pressure:float, mem_pressure:float, total_pressure:float
	 * }
	 */
	public static function getMetrics(int $interval = self::SAMPLE_INTERVAL) {
		// ----- first snapshot of the rate counters -----------------------------
		// hrtime(true) is a monotonic nanosecond clock (PHP 7.3+); unlike
		// microtime() it never jumps backwards on an NTP step mid-sample.
		$start = hrtime(true);
		$cpu1 = self::sampleCpu();
		$disk1 = self::sampleDisks();

		// Let real work accumulate against the counters before we read again.
		if ($interval > 0) {
			sleep($interval);
		}

		// ----- second snapshot -------------------------------------------------
		$cpu2 = self::sampleCpu();
		$disk2 = self::sampleDisks();
		$elapsed = (hrtime(true) - $start) / 1e9; // ns -> s, real window length
		if ($elapsed <= 0) {
			$elapsed = $interval > 0 ? $interval : 1; // clock anomaly guard
		}

		// =====================================================================
		// CPU BASE SIGNALS (system-wide, all logical CPUs aggregated)
		// =====================================================================
		// Derive %idle, %iowait and %steal from the delta between the two
		// /proc/stat aggregate samples - precisely what mpstat does internally.
		$cpuPct = self::computeCpuPercentages($cpu1, $cpu2);
		$cpuIdle = $cpuPct['idle'];
		$cpuIowait = $cpuPct['iowait'];
		$cpuSteal = $cpuPct['steal'];

		// CPU_USAGE = time the CPU was actively executing work.
		//
		// ACCURACY NOTE / deliberate improvement over the shell script:
		// the original used `100 - %idle`, which counts iowait as "busy". But
		// iowait is time the CPU sat *idle* waiting on storage - the core was
		// actually available. Counting it as CPU usage double-charges IO load
		// into BOTH cpu_pressure and io_pressure and badly over-states CPU
		// saturation on storage-bound hosts. We therefore exclude iowait here
		// (it is reported separately as cpu_iowait) so the CPU signal reflects
		// real compute demand. To recover the old behaviour: cpu_usage + cpu_iowait.
		$cpuUsage = round(100.0 - $cpuIdle - $cpuIowait, 2);
		if ($cpuUsage < 0.0) {
			$cpuUsage = 0.0;
		}

		// CORES = scheduling-capacity baseline used to normalise run-queue
		// pressure. Counted from /proc/cpuinfo (equivalent to `nproc`), with a
		// fallback to the per-CPU lines in the /proc/stat sample we already have.
		list($cores, $cpuMhz, $cpuMhzMax) = self::sampleCpuInfo();
		if ($cores < 1) {
			$cores = self::countCpusFromStat();
		}
		if ($cores < 1) {
			$cores = 1; // never divide by zero
		}

		// RUN_QUEUE = number of currently-runnable tasks (4th /proc/loadavg
		// field, "running/total"). RUN_QUEUE_NORM = per-core saturation; a value
		// >= 1 means the CPU is oversubscribed.
		$runQueue = self::sampleRunQueue();
		$runQueueNorm = round($runQueue / $cores, 4);

		// CPU_STEAL_NORM = steal time weighted by actual usage, so an otherwise
		// idle box is not over-penalised for steal it is not really feeling.
		$cpuStealNorm = round(($cpuSteal / 100.0) * ($cpuUsage / 100.0), 4);

		// CPU_CAPACITY = theoretical raw compute capacity (cores x avg MHz).
		// We expose both the *current* aggregate MHz (cores x current avg MHz,
		// which drops when the governor throttles down) and the *maximum*
		// aggregate MHz (cores x max MHz) so the scheduler can see headroom.
		// Reported as whole MHz - sub-MHz precision on an aggregate is noise and
		// integers store cleanly in the (mediumint) DB columns.
		$cpuCapacity = (int)round($cores * $cpuMhz);
		$cpuCapacityMax = (int)round($cores * $cpuMhzMax);

		// =====================================================================
		// MEMORY (system-wide). MemAvailable is used instead of MemFree because
		// it already accounts for reclaimable page cache and slab.
		// =====================================================================
		list($memTotal, $memAvailable) = self::sampleMemInfo();

		// =====================================================================
		// PRESSURE MODELS
		// =====================================================================
		$ioPressure = self::computeIoPressure($disk1, $disk2, $elapsed);
		$cpuPressure = self::computeCpuPressure($cpuUsage, $runQueueNorm, $cpuStealNorm);
		$memPressure = self::computeMemPressure($memAvailable, $memTotal);
		$totalPressure = self::computeTotalPressure($ioPressure, $cpuPressure, $memPressure);

		return [
			'cores'            => $cores,
			'cpu_mhz'          => round($cpuMhz, 2),
			'cpu_mhz_max'      => round($cpuMhzMax, 2),
			'cpu_capacity'     => $cpuCapacity,
			'cpu_capacity_max' => $cpuCapacityMax,
			'cpu_idle'         => round($cpuIdle, 2),
			'cpu_iowait'       => round($cpuIowait, 2),
			'cpu_usage'        => $cpuUsage,
			'cpu_steal'        => round($cpuSteal, 4),
			'cpu_steal_norm'   => $cpuStealNorm,
			'run_queue'        => $runQueue,
			'run_queue_norm'   => $runQueueNorm,
			'mem_total'        => $memTotal,
			'mem_available'    => $memAvailable,
			'io_pressure'      => $ioPressure,
			'cpu_pressure'     => $cpuPressure,
			'mem_pressure'     => $memPressure,
			'total_pressure'   => $totalPressure,
		];
	}

	// =========================================================================
	// SAMPLERS - cheap point-in-time reads of kernel counters
	// =========================================================================

	/**
	 * Read the aggregate CPU line ("cpu ...") from /proc/stat.
	 *
	 * The fields, in order, are cumulative jiffies since boot:
	 *   user nice system idle iowait irq softirq steal guest guest_nice
	 * Trailing fields were introduced across kernel versions (steal in 2.6.11,
	 * guest in 2.6.24, guest_nice in 2.6.33), so any absent field defaults to 0.
	 * Note that `guest`/`guest_nice` are already included in `user`/`nice`, so
	 * we deliberately do NOT add them again when summing totals (avoids the
	 * classic double-count that matters a lot on a VM host).
	 *
	 * The regex anchors on `^cpu\s` so it matches only the aggregate line and
	 * never the per-core `cpu0`/`cpu1`/... lines.
	 *
	 * @return array<string,int> keyed jiffy counters; zeros if unreadable
	 */
	private static function sampleCpu() {
		$zero = [
			'user' => 0, 'nice' => 0, 'system' => 0, 'idle' => 0,
			'iowait' => 0, 'irq' => 0, 'softirq' => 0, 'steal' => 0,
		];
		$stat = self::readFile('/proc/stat');
		if ($stat === '' || !preg_match('/^cpu\s+(.*)$/m', $stat, $m)) {
			return $zero;
		}
		$f = preg_split('/\s+/', trim($m[1]));
		return [
			'user'    => isset($f[0]) ? (int)$f[0] : 0,
			'nice'    => isset($f[1]) ? (int)$f[1] : 0,
			'system'  => isset($f[2]) ? (int)$f[2] : 0,
			'idle'    => isset($f[3]) ? (int)$f[3] : 0,
			'iowait'  => isset($f[4]) ? (int)$f[4] : 0,
			'irq'     => isset($f[5]) ? (int)$f[5] : 0,
			'softirq' => isset($f[6]) ? (int)$f[6] : 0,
			'steal'   => isset($f[7]) ? (int)$f[7] : 0,
		];
	}

	/**
	 * Read per whole-disk IO counters from /proc/diskstats.
	 *
	 * Only whole block devices are returned; partitions are skipped because
	 * their counters overlap the parent disk and `iostat -x` likewise reports
	 * whole devices by default. Whole-disk detection prefers sysfs
	 * (a real device has its own /sys/block/<name> directory) and falls back to
	 * a name-based heuristic when /sys/block is unavailable. The
	 * IO_IGNORE_PREFIXES pseudo-devices are filtered out as well.
	 *
	 * /proc/diskstats column layout (1-based, after the device name) is stable
	 * for the fields we read, regardless of kernel:
	 *   col 4  reads completed        -> index 3
	 *   col 7  time spent reading ms  -> index 6   (read-ticks)
	 *   col 8  writes completed       -> index 7
	 *   col 11 time spent writing ms  -> index 10  (write-ticks)
	 *   col 13 time spent doing I/O   -> index 12  (io-ticks, the %util basis)
	 * Discard stats (cols 15-18, kernel 4.18+) and flush stats (cols 19-20,
	 * kernel 5.5+) are appended after these, so our indices never shift. The
	 * pre-2.6.25 "short" partition format (7 columns) lacks these fields and is
	 * rejected by the column-count guard below.
	 *
	 * @return array<string,array{reads:int,read_ticks:int,writes:int,write_ticks:int,io_ticks:int}>
	 */
	private static function sampleDisks() {
		$candidates = [];
		$stats = self::readFile('/proc/diskstats');
		if ($stats === '') {
			return $candidates;
		}
		foreach (explode("\n", $stats) as $line) {
			$f = preg_split('/\s+/', trim($line));
			// Classic full format = 3 id columns + 11 stat columns = 14 fields.
			// Short partition format (7 fields) and blank lines are rejected.
			if (count($f) < 14) {
				continue;
			}
			$name = $f[2];
			if (self::isIgnoredDevice($name)) {
				continue;
			}
			$candidates[$name] = [
				'reads'       => (int)$f[3],
				'read_ticks'  => (int)$f[6],
				'writes'      => (int)$f[7],
				'write_ticks' => (int)$f[10],
				'io_ticks'    => (int)$f[12],
			];
		}
		return self::keepWholeDisks($candidates);
	}

	/**
	 * Read core count and current/maximum clock speed.
	 *
	 * cores   = number of "processor" entries in /proc/cpuinfo (== nproc).
	 * cpuMhz  = mean of the "cpu MHz" lines (current, governor-dependent speed).
	 *           When /proc/cpuinfo exposes no MHz (some arches/VMs), falls back
	 *           to the cpufreq scaling_cur_freq sysfs values.
	 * cpuMax  = mean per-core maximum MHz from cpufreq sysfs (cpuinfo_max_freq,
	 *           then scaling_max_freq), falling back to the current speed when
	 *           cpufreq is unavailable (common inside guests).
	 *
	 * @return array{0:int,1:float,2:float} [cores, avgCurrentMhz, avgMaxMhz]
	 */
	private static function sampleCpuInfo() {
		$info = self::readFile('/proc/cpuinfo');
		$cores = 0;
		$mhzSum = 0.0;
		$mhzCount = 0;
		if ($info !== '') {
			$cores = preg_match_all('/^processor\s*:/m', $info);
			if (preg_match_all('/^cpu MHz\s*:\s*([0-9.]+)/m', $info, $mm)) {
				foreach ($mm[1] as $v) {
					$mhzSum += (float)$v;
					$mhzCount++;
				}
			}
		}
		// Current MHz: prefer /proc/cpuinfo; fall back to cpufreq (kHz -> MHz).
		if ($mhzCount > 0) {
			$avgMhz = $mhzSum / $mhzCount;
		} else {
			$avgMhz = self::avgCpufreqMhz('scaling_cur_freq');
		}

		// Maximum MHz from cpufreq. cpuinfo_max_freq is the hardware ceiling;
		// scaling_max_freq is the (possibly governor-capped) policy ceiling.
		$avgMax = self::avgCpufreqMhz('cpuinfo_max_freq');
		if ($avgMax <= 0.0) {
			$avgMax = self::avgCpufreqMhz('scaling_max_freq');
		}
		if ($avgMax <= 0.0) {
			// No cpufreq exposed - assume current speed is also the ceiling so
			// capacity math degrades gracefully instead of reporting 0.
			$avgMax = $avgMhz;
		}

		return [(int)$cores, $avgMhz, $avgMax];
	}

	/**
	 * Average a cpufreq attribute (reported in kHz) across all cores, in MHz.
	 *
	 * @param string $attr cpufreq sysfs filename (e.g. cpuinfo_max_freq)
	 * @return float mean MHz, or 0.0 if the attribute is not exposed
	 */
	private static function avgCpufreqMhz(string $attr) {
		$sum = 0.0;
		$count = 0;
		foreach (glob('/sys/devices/system/cpu/cpu[0-9]*/cpufreq/'.$attr) ?: [] as $file) {
			$khz = (float)trim(self::readFile($file));
			if ($khz > 0) {
				$sum += $khz / 1000.0; // kHz -> MHz
				$count++;
			}
		}
		return $count > 0 ? $sum / $count : 0.0;
	}

	/**
	 * Fallback core count: number of per-CPU `cpuN` lines in /proc/stat.
	 *
	 * Used when /proc/cpuinfo lacks "processor" lines (some architectures).
	 *
	 * @return int number of logical CPUs, or 0 if /proc/stat is unreadable
	 */
	private static function countCpusFromStat() {
		$stat = self::readFile('/proc/stat');
		if ($stat === '') {
			return 0;
		}
		return (int)preg_match_all('/^cpu\d+\s/m', $stat);
	}

	/**
	 * Number of currently-runnable tasks from the 4th /proc/loadavg field.
	 *
	 * That field is formatted "running/total"; we take the running count, which
	 * is the instantaneous run-queue depth the scheduler is contending with.
	 *
	 * @return int runnable task count (0 if unreadable)
	 */
	private static function sampleRunQueue() {
		$load = self::readFile('/proc/loadavg');
		if ($load === '') {
			return 0;
		}
		$f = preg_split('/\s+/', trim($load));
		if (!isset($f[3]) || strpos($f[3], '/') === false) {
			return 0;
		}
		$parts = explode('/', $f[3]);
		return (int)$parts[0];
	}

	/**
	 * Read MemTotal and MemAvailable (both in kB) from /proc/meminfo.
	 *
	 * MemAvailable only exists since kernel 3.14. On older kernels we
	 * reconstruct an equivalent estimate of reclaimable memory:
	 *   MemFree + Buffers + (Cached - Shmem) + SReclaimable
	 * (Shmem/tmpfs is subtracted because it lives in Cached but is not
	 * reclaimable). This mirrors the kernel's own MemAvailable heuristic closely
	 * enough for placement decisions and keeps mem_pressure sane on old kernels.
	 *
	 * @return array{0:int,1:int} [memTotalKb, memAvailableKb]
	 */
	private static function sampleMemInfo() {
		$mem = self::readFile('/proc/meminfo');
		if ($mem === '') {
			return [0, 0];
		}
		$get = function ($key) use ($mem) {
			return preg_match('/^'.$key.':\s+(\d+)/m', $mem, $m) ? (int)$m[1] : 0;
		};
		$total = $get('MemTotal');
		if (preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $m)) {
			$avail = (int)$m[1];
		} else {
			// Pre-3.14 fallback estimate.
			$avail = $get('MemFree') + $get('Buffers')
				+ max(0, $get('Cached') - $get('Shmem')) + $get('SReclaimable');
			if ($total > 0 && $avail > $total) {
				$avail = $total;
			}
		}
		return [$total, $avail];
	}

	// =========================================================================
	// DERIVATIONS - turn raw samples into normalised pressure values
	// =========================================================================

	/**
	 * Convert two /proc/stat snapshots into %idle, %iowait and %steal.
	 *
	 * total = user + nice + system + idle + iowait + irq + softirq + steal
	 * (guest/guest_nice intentionally excluded - already folded into user/nice).
	 * Each delta is floored at 0 to absorb counter resets (e.g. CPU hot-unplug).
	 *
	 * @param array<string,int> $a earlier sample
	 * @param array<string,int> $b later sample
	 * @return array{idle:float,iowait:float,steal:float} percentages over the interval
	 */
	private static function computeCpuPercentages(array $a, array $b) {
		$keys = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
		$total = 0;
		foreach ($keys as $k) {
			$d = $b[$k] - $a[$k];
			if ($d < 0) {
				$d = 0; // counter reset / wrap guard
			}
			$total += $d;
		}
		if ($total <= 0) {
			return ['idle' => 100.0, 'iowait' => 0.0, 'steal' => 0.0];
		}
		$idle = max(0, $b['idle'] - $a['idle']);
		$iowait = max(0, $b['iowait'] - $a['iowait']);
		$steal = max(0, $b['steal'] - $a['steal']);
		return [
			'idle'   => ($idle / $total) * 100.0,
			'iowait' => ($iowait / $total) * 100.0,
			'steal'  => ($steal / $total) * 100.0,
		];
	}

	/**
	 * Aggregate per-disk IO into a single 0..1 storage-pressure score.
	 *
	 * For each whole device we derive, over the measured elapsed time T:
	 *   r/s, w/s              completed reads/writes per second
	 *   iops  = r/s + w/s     throughput weight for this device
	 *   await = (read_ticks + write_ticks delta) / (reads + writes delta)  [ms/io]
	 *   util  = io_ticks delta / (T * 1000) * 100                          [% busy]
	 *
	 * Devices are then combined the same way the shell script does:
	 *   - IOPS-weighted average latency (aa) and utilisation (au), so a busy
	 *     disk dominates the signal
	 *   - worst-case latency (ma) and utilisation (mu) across all disks
	 *   - blend average with worst case:  fa = aa*0.7 + ma*0.3,
	 *                                      fu = au*0.7 + mu*0.3
	 *   - normalise:  io = fa/IO_AWAIT_FULL_MS + fu/100.0, clamped to [0, 1].
	 *
	 * Using the real measured elapsed time (rather than the nominal interval)
	 * keeps %util and IOPS accurate even if the sleep ran slightly long.
	 *
	 * @param array $a earlier disk snapshot from sampleDisks()
	 * @param array $b later disk snapshot from sampleDisks()
	 * @param float $elapsed measured seconds between snapshots
	 * @return float storage pressure in [0, 1], rounded to 4 dp
	 */
	private static function computeIoPressure(array $a, array $b, float $elapsed) {
		$t = $elapsed > 0 ? $elapsed : 1.0;

		$ti = 0.0; // total iops (weight)
		$wa = 0.0; // sum of await * iops
		$wu = 0.0; // sum of util  * iops
		$ma = 0.0; // worst await
		$mu = 0.0; // worst util

		foreach ($b as $name => $cur) {
			if (!isset($a[$name])) {
				continue; // device appeared mid-sample; no baseline to diff
			}
			$prev = $a[$name];
			$dReads  = max(0, $cur['reads']  - $prev['reads']);
			$dWrites = max(0, $cur['writes'] - $prev['writes']);
			$dRTicks = max(0, $cur['read_ticks']  - $prev['read_ticks']);
			$dWTicks = max(0, $cur['write_ticks'] - $prev['write_ticks']);
			$dIoTime = max(0, $cur['io_ticks'] - $prev['io_ticks']);

			$ios = $dReads + $dWrites;
			$iops = $ios / $t;

			$await = $ios > 0 ? ($dRTicks + $dWTicks) / $ios : 0.0;
			$util = ($dIoTime / ($t * 1000.0)) * 100.0;
			if ($util > 100.0) {
				$util = 100.0; // a single device cannot exceed 100% busy
			}

			$ti += $iops;
			$wa += $await * $iops;
			$wu += $util * $iops;
			if ($await > $ma) {
				$ma = $await;
			}
			if ($util > $mu) {
				$mu = $util;
			}
		}

		if ($ti > 0) {
			$aa = $wa / $ti;
			$au = $wu / $ti;
		} else {
			$aa = 0.0;
			$au = 0.0;
		}

		// Blend typical behaviour (70%) with the worst single bottleneck (30%).
		$fa = ($aa * 0.7) + ($ma * 0.3);
		$fu = ($au * 0.7) + ($mu * 0.3);

		$io = ($fa / self::IO_AWAIT_FULL_MS) + ($fu / 100.0);
		return self::clamp01($io);
	}

	/**
	 * Scheduler- and contention-aware CPU pressure in [0, 1].
	 *
	 *   cpu = (usage/100)*0.6   utilisation is the primary driver
	 *       + run_queue_norm*0.3  per-core scheduling backlog
	 *       + steal_norm*0.1      hypervisor/virtualisation overhead
	 *
	 * @param float $usage        CPU utilisation percentage (0..100, iowait-excluded)
	 * @param float $runQueueNorm runnable tasks per core
	 * @param float $stealNorm    usage-weighted steal fraction (0..1)
	 * @return float clamped to [0, 1], rounded to 4 dp
	 */
	private static function computeCpuPressure(float $usage, float $runQueueNorm, float $stealNorm) {
		$cpu = ($usage / 100.0) * 0.6 + $runQueueNorm * 0.3 + $stealNorm * 0.1;
		return self::clamp01($cpu);
	}

	/**
	 * Memory pressure = fraction of memory NOT available (higher = worse).
	 *
	 *   mem = 1 - (MemAvailable / MemTotal)
	 *
	 * @param int $available MemAvailable in kB
	 * @param int $total     MemTotal in kB
	 * @return float clamped to [0, 1], rounded to 4 dp
	 */
	private static function computeMemPressure(int $available, int $total) {
		if ($total <= 0) {
			return 0.0;
		}
		$mem = 1.0 - ($available / $total);
		return self::clamp01($mem);
	}

	/**
	 * Weighted node saturation score: IO dominates, CPU secondary, memory last.
	 *
	 *   total = io*0.5 + cpu*0.3 + mem*0.2
	 *
	 * @return float rounded to 4 dp (not clamped - inputs are already in [0,1])
	 */
	private static function computeTotalPressure(float $io, float $cpu, float $mem) {
		return round(($io * 0.5) + ($cpu * 0.3) + ($mem * 0.2), 4);
	}

	// =========================================================================
	// SMALL HELPERS
	// =========================================================================

	/**
	 * Reduce a /proc/diskstats device map to just whole disks.
	 *
	 * Primary detection is sysfs: a real device has its own /sys/block/<name>
	 * directory, whereas a partition lives under /sys/block/<disk>/<part>. When
	 * /sys/block is entirely unavailable (e.g. /sys not mounted) we fall back to
	 * a name heuristic: a device is a partition if another listed device name is
	 * a prefix of it and the remainder looks like a partition suffix (optional
	 * 'p' then digits) - e.g. sda1 of sda, nvme0n1p1 of nvme0n1, mmcblk0p1.
	 *
	 * @param array<string,array> $candidates name => stats for all non-ignored devices
	 * @return array<string,array> name => stats for whole disks only
	 */
	private static function keepWholeDisks(array $candidates) {
		if (is_dir('/sys/block')) {
			$out = [];
			foreach ($candidates as $name => $stats) {
				if (is_dir('/sys/block/'.$name)) {
					$out[$name] = $stats;
				}
			}
			return $out;
		}
		// Sysfs fallback: name-based partition detection.
		$names = array_keys($candidates);
		$out = [];
		foreach ($candidates as $name => $stats) {
			if (!self::isPartitionName($name, $names)) {
				$out[$name] = $stats;
			}
		}
		return $out;
	}

	/**
	 * Heuristic: is $name a partition of some other device in $allNames?
	 *
	 * True when another listed device name is a prefix of $name and the
	 * remainder looks like a partition suffix - optional 'p' then digits. This
	 * matches sda1 -> sda, nvme0n1p1 -> nvme0n1, mmcblk0p1 -> mmcblk0, while
	 * leaving whole devices (sda, sdb, nvme0n1, md0) untouched. Only used as a
	 * fallback when /sys/block is unavailable for authoritative detection.
	 *
	 * @param string $name device name under test
	 * @param string[] $allNames every candidate device name
	 * @return bool true if $name appears to be a partition of another device
	 */
	private static function isPartitionName(string $name, array $allNames) {
		foreach ($allNames as $other) {
			if ($other !== $name
				&& strncmp($name, $other, strlen($other)) === 0
				&& preg_match('/^p?\d+$/', substr($name, strlen($other)))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a /proc/diskstats device name should be excluded from IO stats.
	 *
	 * @param string $name device name (e.g. sda, nvme0n1, loop3)
	 * @return bool true if it matches an IO_IGNORE_PREFIXES entry
	 */
	private static function isIgnoredDevice(string $name) {
		foreach (self::IO_IGNORE_PREFIXES as $prefix) {
			if (strncmp($name, $prefix, strlen($prefix)) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clamp a value to [0, 1] and round to 4 decimals (the script's precision).
	 *
	 * @param float $v raw value
	 * @return float value constrained to [0, 1]
	 */
	private static function clamp01(float $v) {
		if ($v > 1.0) {
			$v = 1.0;
		} elseif ($v < 0.0) {
			$v = 0.0;
		}
		return round($v, 4);
	}

	/**
	 * Read a file, returning '' on any failure so callers can fail soft.
	 *
	 * @param string $path absolute path to read
	 * @return string file contents, or '' if unreadable
	 */
	private static function readFile(string $path) {
		$data = @file_get_contents($path);
		return $data === false ? '' : $data;
	}
}
