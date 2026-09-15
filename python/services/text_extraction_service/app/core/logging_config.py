import logging
from pathlib import Path

from pythonjsonlogger import jsonlogger


logger = logging.getLogger(__name__)

current_file = Path(__file__).resolve()
base_dir = current_file.parents[3]
logs_dir = base_dir / "logs"
logs_dir.mkdir(parents=True, exist_ok=True)

file_handler = logging.FileHandler(logs_dir / "app.log")

json_formatter = jsonlogger.JsonFormatter(
    "%(name)s %(asctime)s %(levelname)s %(filename)s %(lineno)s %(process)d %(message)s",
    rename_fields={"levelname": "severity", "asctime": "timestamp"},
    datefmt="%Y-%m-%dT%H:%M:%SZ",
)

file_handler.setFormatter(json_formatter)

logger.addHandler(file_handler)

logger.setLevel(logging.DEBUG)
