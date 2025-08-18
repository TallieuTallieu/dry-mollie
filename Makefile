-include .env
export

Red=\033[0;31m
Green=\033[0;32m
Yellow=\033[0;33m
Blue=\033[0;34m
Purple=\033[0;35m
Cyan=\033[0;36m
Orange=\033[0;33m
NC=\033[0m

## DOCKER ##
docker-init:
	@if ! docker info >/dev/null 2>&1; then \
		echo "Docker is not running, starting Docker..."; \
		open -a Docker; \
		while ! docker info >/dev/null 2>&1; do \
			echo "Waiting for Docker to start..."; \
			sleep 5; \
		done; \
		echo "Docker is now running."; \
	else \
		echo "Docker is already running."; \
	fi
.PHONY: docker-init

docker: docker-init
	@if [ -z "$$(docker compose ps -q dry-mollie-dev)" ]; then \
		docker compose up -d --build; \
		else \
		echo "dry-mollie-dev is running."; \
		fi
.PHONY: docker

docker-exec: docker
	docker compose exec dry-mollie-dev bash
.PHONY: docker-exec
