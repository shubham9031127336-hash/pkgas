@echo off
REM Start the Dashboard WebSocket Server
REM Requires Node.js (v18+)
echo Starting Dashboard WebSocket Server...
echo URL: ws://localhost:8089
echo Press Ctrl+C to stop.
cd /d "%~dp0"
node dashboard-ws-server.js
pause
