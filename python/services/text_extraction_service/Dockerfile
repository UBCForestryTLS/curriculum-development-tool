FROM python:3.13.14-trixie

WORKDIR /usr/src

RUN apt-get update && apt-get install -y tesseract-ocr

COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt

# download English NLP model required by BERTopic
RUN python -m spacy download en_core_web_sm

RUN mkdir app/ logs/ app/models/

COPY . .

RUN groupadd srvc \
    && useradd -m -g srvc srvc

RUN chown -R srvc app/ logs/
RUN chmod -R 700 app/ logs/

USER srvc

CMD ["uvicorn", "app.api.routes:app", "--host", "0.0.0.0", "--port", "80" ]