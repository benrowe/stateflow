.PHONY: init build build-base workspace test test-single test-coverage quality lint lint-fix check clean help

# Docker configuration
IMAGE_NAME = stateflow-php
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(IMAGE_NAME)
DOCKER_RUN_IT = docker run --rm -it -v $(PWD):/app -w /app $(IMAGE_NAME)

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image with dependencies
	@echo "Building Docker image..."
	docker build --target dev -t $(IMAGE_NAME) .

build-base: ## Build base Docker image without dependencies
	@echo "Building base Docker image..."
	docker build --target base -t $(IMAGE_NAME)-base .

init: build ## Build Docker image (dependencies included)
	@echo "Docker image built successfully with all dependencies!"

workspace: ## Enter the Docker container workspace
	@echo "Entering workspace..."
	$(DOCKER_RUN_IT) /bin/bash

test: ## Run PHPUnit tests (parallel execution with 4 processes)
	$(DOCKER_RUN) composer test

test-single: ## Run PHPUnit tests without parallelization
	$(DOCKER_RUN) composer test:single

test-coverage: ## Run PHPUnit tests with coverage report
	$(DOCKER_RUN) composer test:coverage

quality: ## Run PHPStan static analysis
	$(DOCKER_RUN) composer quality

lint: ## Check code style
	$(DOCKER_RUN) composer lint

lint-fix: ## Fix code style
	$(DOCKER_RUN) composer lint:fix

check: ## Run all checks (code style, PHPStan, tests)
	$(DOCKER_RUN) composer check

clean: ## Remove vendor directory and composer.lock
	@echo "Cleaning up..."
	rm -rf vendor composer.lock
