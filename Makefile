SCRIPT = ./tests/prevTest.php
run70:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:7.0-cli php $(SCRIPT)
run71:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:7.1-cli php $(SCRIPT)
run72:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:7.2-cli php $(SCRIPT)
run73:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:7.3-cli php $(SCRIPT)
run74:
	@docker run -it --rm --name tmp -v "$$PWD":/myapp -w /myapp php:7.4-cli php $(SCRIPT)
run_all:
	@make run70 && make run71 && make run72 && make run73 && make run74
