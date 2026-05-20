.PHONY: help
.DEFAULT_GOAL := help

SCRIPT = ./tests/prevTest.php

help:
	@echo "Usage: make [target]"
	@echo ""
	@echo "Targets:"
	@echo "  run82           Run tests in Docker (PHP 8.2)"
	@echo "  run83           Run tests in Docker (PHP 8.3)"
	@echo "  run84           Run tests in Docker (PHP 8.4)"
	@echo "  run             Build Docker image and start FrankenPHP server"
	@echo "  update-examples Clone/refresh all project-type examples defined in examples/meta.json"

run82:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.2-cli php $(SCRIPT)
run83:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.3-cli php $(SCRIPT)
run84:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:8.4-cli php $(SCRIPT)

run:
	@docker build -t recombinator-dev .
	@docker run -it --rm -v "$$PWD":/app -p 85:80 -w /app recombinator-dev sh -c "composer i && frankenphp run --config /etc/frankenphp/Caddyfile"

update-examples:
	@php bin/update-examples.php
