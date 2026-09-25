FROM python:3.13.14-trixie

WORKDIR /usr/src

COPY requirements.txt ./

RUN pip install --no-cache-dir -r requirements.txt

RUN mkdir app/ logs/

# download nltk package to recommended system location
RUN python -m nltk.downloader -d /usr/share/nltk_data "stopwords"

COPY . .

RUN groupadd srvc \
    && useradd -m -g srvc srvc

RUN chown -R srvc app/ logs/ /usr/share/nltk_data/ \
    && chmod -R 700 app/ logs/ /usr/share/nltk_data/

USER srvc

CMD ["uvicorn", "app.api.routes:app", "--host", "0.0.0.0", "--port", "80" ]