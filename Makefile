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
	@echo
	@echo "Стандартные цели ~/projects:"
	@grep -hE '^[a-zA-Z0-9_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

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

# --- стандартные цели ~/projects (make help) ---

.PHONY: install
install: ## поставить зависимости
	composer install

.PHONY: dev
dev: ## запустить для разработки
	@echo "запускать нечего: это библиотека"

.PHONY: test
test: ## тесты
	composer test

.PHONY: lint
lint: ## статический анализ
	composer check

.PHONY: build
build: ## сборка
	@echo "сборки нет"

.PHONY: audit
audit: ## уязвимости в зависимостях
	composer audit

.PHONY: outdated
outdated: ## устаревшие зависимости
	composer outdated --direct
