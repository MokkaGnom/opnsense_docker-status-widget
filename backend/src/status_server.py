from src.docker_status import DockerStatus
from http.server import BaseHTTPRequestHandler
from datetime import datetime
import json


class HealthcheckHandler(BaseHTTPRequestHandler):
   
    def _get_docker_status(self, docker_status:DockerStatus)->list[dict]:
        return docker_status.get_container_data()
        
   
    def do_GET(self):
        if self.path == "/self":
            response = {
            "status": "ok",
            "timestamp": datetime.now().isoformat() + "Z"
        }
        elif self.path == "/":
            response = self._get_docker_status(self.server.docker_status)    
        else:
            self.send_response(404)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "not found"}).encode())
            return

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(response).encode())

    # Deactivate logging
    def log_message(self, format, *args):
        return
