FROM python:3.13.14-trixie

WORKDIR /usr/src

COPY requirements.txt ./

RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN groupadd srvc \
    && useradd -m -g srvc srvc

RUN mkdir logs/
RUN chown -R srvc app/ logs/
RUN chmod -R 700 app/ logs/

USER srvc

CMD ["uvicorn", "app.api.routes:app", "--host", "0.0.0.0", "--port", "80" ]