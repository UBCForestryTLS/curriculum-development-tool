from enum import StrEnum
from typing import TypedDict, NotRequired

class Course(TypedDict):
    code: str = ""
    number: int = 0
    title: str = ""
    term: str = ""
    year: int = 0
    level: str = ""
    description: str = ""
    goals: list[str] = []
    assessments: list[tuple[str, int | float]] = []
    topics: list[str] = []
    materials: list[dict] = []

class StatusEnum(StrEnum):
    SUCCESS = 'success'
    ERROR = 'error'

class ParseResponse(TypedDict):
    status: StatusEnum
    data: NotRequired[Course]
    message: str