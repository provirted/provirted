# ProVirted

## About

Easy management of Virtualization technologies including KVM, Xen, OpenVZ, Virtuozzo, and LXC/LXD including unified commands, monitoring, template management, and many more features.

## TODO

* add jensuring memory limits 
* store vzid only in the vzid field not hostname for kvm
  * it looks like we can grab information about the vm by using virt-inspector --no-applications -d <vzid> to get a xml formatted output of basic os info including hostnmae
* Add template exists checks to the create code
* Check your passwords beginning with hyphens interfere with the option parsing and that if a double dash will resolve the issue
* fix **reset-password** command adding in detection of windows and skipping if not
* possibly utilize virt-resize in **update** call instead of qemu-img resize
* add bash/zsh completion suggestions for ip fields (except client ip) having it show the ips on the host server excluding ones in use
* add escapeshellarg() calls around any vars being passed through a exec type call
* fix the restore script to work with kvmv2 os.qcow2 files
* create public website on github [https://github.com/provirted/provirted.github.io](provirted/provirted.github.io)
* add wiki entries
* add lxc support  [https://linuxcontainers.org/lxd/docs/master/](LXD Docs)
* add **self-update** command for downloading the latest phar and replacing it
* add **install** command - Installs PreRequisites, Configures Software for our setup
* add **config** command - Management of the various settings
* work on **test** command to test a vPs or the host
  * add server option to **test** command to perform various self diagnostics to check on the health and prepairedness of the system
  * add option to tweak checks for template testing or client vps testing
  * add gpt 2tb+ test
  * add package update test
  * add code to try ssh even without a ping reply
  * add optional syslog/messages checking dhcp server for DHCPACK from vps
* remove reliance on local scripts

buildebtablesrules
run_buildebtables.sh
tclimit

create_libvirt_storage_pools.sh
vps_get_image.sh
vps_kvm_lvmcreate.sh
vps_kvm_lvmresize.sh
vps_swift_restore.sh

vps_kvm_password_manual.php
vps_kvm_setup_password_clear.sh

vps_kvm_screenshot.sh
vps_kvm_screenshot_swift.sh
vps_refresh_vnc.sh

## Commands

* **create** Creates a Virtual Machine.
* **destroy** Destroys a Virtual Machine.
* **enable** Enables a Virtual Machine.
* **delete** Deletes a Virtual Machine.
* **backup** Creates a Backup of a Virtual Machine.
* **restore** Restores a Virtual Machine from Backup.
* **stop** Stops a Virtual Machine.
* **start** Starts a Virtual Machine.
* **restart** Restarts a Virtual Machine.
* **block-smtp** Blocks SMTP on a Virtual Machine.
* **update** Change the hd, cpu, memory, password, etc of a Virtual Machine.
* **reset-password** Resets/Clears a Password on a Virtual Machine.
* **add-ip** Adds an IP Address to a Virtual Machine.
* **remove-ip** Removes an IP Address from a Virtual Machine.
* **cd** CD-ROM management functionality
* **test** Perform various self diagnostics to check on the health and prepairedness of the system.

### Debugging

you can add -v to increase verbosity by 1 and see all the commands being run, or a second time to also see the output and exit status of each command

## Developer Links

* [KVMv2 Install](https://wiki.interserver.net/index.php/Kvmv2#latest_installer) KVMv2 Installer Code
* [walkor/webman](https://github.com/walkor/webman) Webman GitHub repo
* [workerman.net/doc/webman](https://www.workerman.net/doc/webman) Webman Docs

### CLI Frameworks
* [adhocore/php-cli](https://github.com/adhocore/php-cli): PHP Console Application made easy- build great console apps with ease. Comes with Zero Dependency and Autocompletion support. Think of it as a PHP cli application framework.
* [alecrabbit/php-console-spinner](https://github.com/alecrabbit/php-console-spinner): Colorful extremely flexible spinner for * [async] php cli applications
* [aplus-framework/aplus](https://github.com/aplus-framework/aplus): Aplus Command Line Tool
* [auraphp/Aura.Cli](https://github.com/auraphp/Aura.Cli): Command-Line Interface tools
* [c9s/CLIFramework](https://github.com/c9s/CLIFramework): A powerful command line application framework for PHP. It's an extensible, flexible component, You can build your command-based application in seconds!
* [Cilex/Cilex](https://github.com/Cilex/Cilex): Cilex a lightweight framework for creating PHP CLI scripts inspired by Silex
* [clue/reactphp-stdio](https://github.com/clue/reactphp-stdio): Async, event-driven and UTF-8 aware console input & output (STDIN, STDOUT) for truly interactive CLI applications, built on top of ReactPHP.
* [contributte/console: :boom](https://github.com/contributte/console): Best minimal console (symfony/console) to Nette Framework (@nette)
* [curruwilla/console-pretty-log](https://github.com/curruwilla/console-pretty-log): Simple and customizable console log output for CLI apps.
* [decodelabs/terminus](https://github.com/decodelabs/terminus): Simple CLI interactions for PHP
* [DevAmirul/PHP-MVC-Framework](https://github.com/DevAmirul/PHP-MVC-Framework): A simple, fast, and small PHP MVC Framework that enables to develop of modern applications with standard MVC structure and CLI command line tools. This framework uses dependencies as minimum as possible. Inspired by Laravel.
* [inhere/php-console](https://github.com/inhere/php-console): 🖥 PHP CLI application library, provide console options, arguments parse, console controller/command run, color style, user interactive, format information show and more. A comprehensive PHP command line application library. Provides console options, parameter analysis, command execution, color style output, user information interaction, and special format information display
* [JBlond/php-cli](https://github.com/JBlond/php-cli): php command line / cli scritping and coloring classes
* [JBZoo/Cli](https://github.com/JBZoo/Cli): The framework helps create complex CLI apps and provides new tools for Symfony/Console, Symfony/Process.
* [jc21/clitable](https://github.com/jc21/clitable): CLI Table Output for PHP
* [kristuff/mishell](https://github.com/kristuff/mishell): A mini PHP library to build beautiful CLI apps and reports
* [kylekatarnls/simple-cli](https://github.com/kylekatarnls/simple-cli): A simple command line framework
* [meklis/console-client](https://github.com/meklis/console-client): SSH/Telnet clients with helpers
* [minicli/minicli](https://github.com/minicli/minicli): A minimalist framework for command-line applications in PHP
* [mix-php/mix](https://github.com/mix-php/mix): ☄️ PHP CLI mode development framework, supports Swoole, WorkerMan, FPM, CLI-Server / PHP 命令行模式开发框架，支持 Swoole、Swow、WorkerMan、FPM、CLI-Server
* [openai-php/client](https://github.com/openai-php/client): ⚡️ OpenAI PHP is a supercharged community-maintained PHP API client that allows you to interact with OpenAI API.
* [phppkg/cli-markdown](https://github.com/phppkg/cli-markdown): Render colored Markdown contents on console terminal
* [php-school/cli-menu](https://github.com/php-school/cli-menu): 🖥 Build beautiful PHP CLI menus. Simple yet Powerful. Expressive DSL.
* [php-toolkit/cli-utils](https://github.com/php-toolkit/cli-utils): Provide some useful utils for the php CLI. console color, CLI env, CLI code highlighter.
* [php-toolkit/pflag](https://github.com/php-toolkit/pflag): Generic PHP command line flags parse library.
* [php-tui/cli-parser](https://github.com/php-tui/cli-parser): Type-safe CLI argument parser
* [php-tui/php-tui](https://github.com/php-tui/php-tui): PHP TUI
* [provirted/provirted](https://github.com/provirted/provirted): Easy management of Virtualization technologies including KVM, Xen, OpenVZ, Virtuozzo, and LXC/LXD including unified commands, monitoring, template management, and many more features.
* [splitbrain/php-cli](https://github.com/splitbrain/php-cli): PHP library to build command line tools
* [symfony/console](https://github.com/symfony/console): Eases the creation of beautiful and testable command line interfaces
* [theofidry/console](https://github.com/theofidry/console): Library for creating CLI commands or applications
* [thephpleague/climate](https://github.com/thephpleague/climate?tab=readme-ov-file): PHP's best friend for the terminal.
* [utopia-php/cli](https://github.com/utopia-php/cli): Lite & fast micro PHP framework for building CLI tools that is **easy to learn**.
* [vanilla/garden-cli](https://github.com/vanilla/garden-cli): A full-featured, yet ridiculously simple commandline parser for your next php cli script. Stop fighting with getopt().
* [WebFiori/cli](https://github.com/WebFiori/cli): Class library to simplify the process of creating command line based applications using PHP.


## Dev Notes/Code

Fixing CentOS 6/7 Hosts
This fixs several issues with CentOS 6 and CentOS 7 servers
```bash
if [ -e /etc/redhat-release ]; then
  rhver="$(cat /etc/redhat-release |sed s#"^.*release \([0-9][^ ]*\).*$"#"\1"#g)"
  rhmajor="$(echo "${rhver}"|cut -c1)"
  if [ ${rhmajor} -lt 7 ]; then
    if [ "$rhver" = "6.108" ]; then
      rhver="6.10";
    fi;
    sed -i "/^mirrorlist/s/^/#/;/^#baseurl/{s/#//;s/mirror.centos.org\/centos\/$releasever/vault.centos.org\/${rhver}/}" /etc/yum.repos.d/*B*;
  fi;
  if [ ${rhmajor} -eq 6 ]; then
    yum install epel-release yum-utils -y;
    yum install http://rpms.remirepo.net/enterprise/remi-release-6.rpm -y;
    yum-config-manager --enable remi-php73;
    yum update -y;
  elif [ ${rhmajor} -eq 7 ]; then
    yum install epel-release yum-utils -y;
    yum install http://rpms.remirepo.net/enterprise/remi-release-7.rpm -y;
    yum-config-manager --enable remi-php74;
    yum update -y;
    yum install php74 php74-php-{bcmath,cli,pdo,devel,gd,intl,json,mbstring} \
      php74-php-{opcache,pear,pecl-ev,pecl-event,pecl-eio,pecl-inotify,xz,xml} \
      php74-php-{xmlrpc,sodium,soap,snmp,process,pecl-zip,pecl-xattr} \
      php74-php-{pecl-yaml,pecl-ssh2,mysqlnd,pecl-igbinary,pecl-imagick} -y;
    for i in /opt/remi/php74/root/usr/bin/*; do
      ln -s "$i" /usr/local/bin/;
    done;
  fi;
fi
```

Updating the host
```bash
ssh my@mynew php /home/my/scripts/vps/qs_list.php all|grep -v 'Now using' > servers.csv; ssh my@mynew php /home/my/scripts/vps/vps_list.php sshable|grep -v 'Now using' >> servers.csv; tvps;
tsessrun 'cd /root/cpaneldirect && git pull --all && ln -fs /root/cpaneldirect/provirted.phar /usr/local/bin/provirted && php provirted.phar bash --bind provirted.phar --program provirted.phar > /etc/bash_completion.d/provirted_completion && chmod +x /etc/bash_completion.d/provirted_completion && if [ -e /etc/apt ]; then apt-get update &&  apt-get autoremove -y --purge && apt-get dist-upgrade -y && apt-get autoremove -y --purge && apt-get clean; else yum update -y --skip-broken; fi'
tsessrun 'cd /root/cpaneldirect && git pull --all && ln -fs /root/cpaneldirect/provirted.phar /usr/local/bin/provirted && php provirted.phar bash --bind provirted.phar --program provirted.phar > /etc/bash_completion.d/provirted_completion && chmod +x /etc/bash_completion.d/provirted_completion && if [ -e /etc/apt ]; then apt-get update &&  apt-get autoremove -y --purge && apt-get dist-upgrade -y && apt-get autoremove -y --purge && apt-get clean; else yum update -y --skip-broken; fi && if [ "$(php -v|head -n 1|cut -c5)" = 7 ]; then exit; fi;'
```
