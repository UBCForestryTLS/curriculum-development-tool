from mangum import Mangum

from app.main import app


# Send API Gateway requests through the same routes we use locally.
handler = Mangum(app, lifespan="off")
