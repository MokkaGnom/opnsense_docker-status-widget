from http.server import ThreadingHTTPServer
import json
import os
from src.docker_status import DockerStatus
from src.status_server import HealthcheckHandler


HTTP_PORT = 42679
HTTP_HOST = "0.0.0.0"

_raw_checks = os.getenv("DOCKER_HTTP_CHECKS", "{}").strip()
try:
    DOCKER_HTTP_CHECKS = json.loads(_raw_checks) if _raw_checks else {}
except json.JSONDecodeError:
    DOCKER_HTTP_CHECKS = {}


def main():
    server = ThreadingHTTPServer((HTTP_HOST, HTTP_PORT), HealthcheckHandler)
    server.docker_status = DockerStatus(DOCKER_HTTP_CHECKS)
    print(f"Docker-Status-Server running on http://{HTTP_HOST}:{HTTP_PORT}/")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("Keyboard Interrupt: Shutting down...")
        server.shutdown()
        server.server_close()


if __name__ == "__main__":
    main()