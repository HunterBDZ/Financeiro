@echo off
rem Para o terminal: financeiro abrir, iniciar, parar, status ou senha LOGIN
"%~dp0bin\php.exe" -n "%~dp0gerente.php" %*
