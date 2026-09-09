.PHONY: up down shell build install test analyse \
        run-server run-server-debug run-client run-client-debug bench

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec php bash

htop: up
	docker compose exec php htop

install: up
	docker compose exec php composer install

test: up
	docker compose exec php composer test

analyse: up
	docker compose exec php composer analyse

run-server: up
	docker compose exec php php bin/server.php

# The image ships xdebug with start_with_request=trigger, so nothing reaches
# for a debugger unless asked. These targets are the ask; point your IDE at
# port 9003 first, or the connection attempt just times out and the run
# continues.

run-server-debug: up
	docker compose exec php bash -c "XDEBUG_TRIGGER=1 php bin/server.php"

run-client: up
	docker compose exec php php bin/client.php $(ARGS)

run-client-debug: up
	docker compose exec php bash -c "XDEBUG_TRIGGER=1 php bin/client.php $(ARGS)"

# Requires a server already running (make run-server, in another terminal).
bench: up
	docker compose exec php php bin/bench.php $(ARGS)
