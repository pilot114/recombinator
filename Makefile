SCRIPT = ./tests/prevTest.php
run82:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.2-cli php $(SCRIPT)
run83:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.3-cli php $(SCRIPT)
run84:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.4-cli php $(SCRIPT)
run_all:
	@make run82 && make run83 && make run84
run:
	@docker build -t recombinator-dev .
	@docker run -it --rm -v "$$PWD":/app -p 80:80 -w /app recombinator-dev sh -c "composer i && frankenphp run --config /etc/frankenphp/Caddyfile"
