all: nodev tui-deps phar bundle-tui

dev:
	composer update --with-all-dependencies -v -o --ansi --dev

nodev:
	composer update --with-all-dependencies -v -o --ansi --no-dev

completion:
	rm -f provirted_completion
	php provirted.php bash --bind provirted.phar --program provirted.phar > provirted_completion
	chmod +x provirted_completion

phar:
	rm -f provirted.phar
	# compression breaks pvdisplay
	php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar
	chmod +x provirted.phar

tui-deps:
	# Install the optional PHP 8.1+ TUI dependency set into vendor-tui/.
	# Kept out of the main composer.json so the core phar stays PHP 7.4
	# resolvable. Requires a PHP 8.1+ build host (SugarCraft needs ^8.3).
	COMPOSER=composer-tui.json composer update --with-all-dependencies -o --ansi --no-interaction

bundle-tui:
	# Graft the installed vendor-tui/ tree into the already-built phar.
	php -d phar.readonly=0 bin/bundle-tui.php provirted.phar vendor-tui

install:
	cp provirted_completion /etc/bash_completion.d/provirted
	ln -fs /root/cpaneldirect/provirted.phar /usr/local/bin/provirted

internals:
	rm -rf app/Command/InternalsCommand
	php provirted.php generate-internals

copy:
	cp -fv provirted.phar ../vps_host_server/provirted.phar && \
		cd ../vps_host_server && \
		git pull --all && \
		git commit -m 'Updating provirted.phar' provirted.phar && \
		git push --all && \
		git pull --all && \
		cd ../provirted
