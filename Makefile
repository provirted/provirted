all: nodev phar completion

pubcopy: publish copy

publish: nodev phar completion commit

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
	php provirted.php archive --composer=composer.json --app-bootstrap --executable --compress=gz provirted.phar
	chmod +x provirted.phar

install:
	cp provirted_completion /etc/bash_completion.d/provirted
	ln -fs /root/cpaneldirect/cli/provirted.phar /usr/local/bin/provirted

internals:
	rm -rf app/Command/InternalsCommand
	php provirted.php generate-internals

commit:
	git pull --all && \
		git commit -a && \
		git push --all && \
		git pull --all

copy:
	cp -fv provirted.phar ../vps_host_server/provirted.phar && \
		cd ../vps_host_server && \
		git pull --all && \
		git commit -m 'Updating provirted.phar' provirted.phar && \
		git push --all && \
		git pull --all && \
		cd ../provirted
