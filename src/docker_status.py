import docker, requests, datetime


def calculate_cpu_percent(stats):
    cpu_delta = (
        stats["cpu_stats"]["cpu_usage"]["total_usage"]
        - stats["precpu_stats"]["cpu_usage"]["total_usage"]
    )
    system_delta = (
        stats["cpu_stats"]["system_cpu_usage"]
        - stats["precpu_stats"]["system_cpu_usage"]
    )
    if system_delta > 0 and cpu_delta > 0:
        cores = len(stats["cpu_stats"]["cpu_usage"]["percpu_usage"])
        return round((cpu_delta / system_delta) * cores * 100.0, 2)
    return 0.0


def calculate_mem_mb(stats):
    usage = stats["memory_stats"].get("usage", 0)
    return round(usage / (1024 * 1024), 2)


class DockerStatus:

    def __init__(self, http_checks: dict[str, str] = dict()):
        self.docker_client: docker.DockerClient = docker.from_env()
        self.http_checks: dict[str, str] = http_checks

    def _http_healthcheck(self, name) -> tuple[str, str]:
        if name not in self.http_checks:
            return "-", ""
        try:
            r = requests.get(self.http_checks[name], timeout=2)
            if r.status_code == 200:
                return "HTTP OK", "healthy"
            return f"HTTP {r.status_code}", "unhealthy"
        except:
            return "HTTP FAIL", "unhealthy"
        
    def get_container_data(self) -> list:
        container_data = []
        for c in self.docker_client.containers.list(all=True):
            stats = {}
            cpu = 0
            mem = 0

            if c.status == "running":
                try:
                    stats = c.stats(stream=False)
                    cpu = calculate_cpu_percent(stats)
                    mem = calculate_mem_mb(stats)
                except:
                    pass

            started = c.attrs["State"].get("StartedAt")
            uptime = "-"
            if started and c.status == "running":
                dt = datetime.datetime.fromisoformat(started.replace("Z", "+00:00"))
                uptime = str(datetime.datetime.now(datetime.timezone.utc) - dt).split(".")[0]

            docker_health = c.attrs["State"].get("Health", {})
            docker_health_status = docker_health.get("Status", "-")

            http_health, http_class = self._http_healthcheck(c.name)

            health_display = docker_health_status
            health_class = docker_health_status

            if http_health != "-":
                health_display = http_health
                health_class = http_class

            container_data.append(
                {
                    "name": c.name,
                    "status": c.status,
                    "uptime": uptime,
                    "cpu": cpu,
                    "mem": mem,
                    "restarts": c.attrs["RestartCount"],
                    "health": health_display,
                    "health_class": health_class,
                }
            )
        return container_data