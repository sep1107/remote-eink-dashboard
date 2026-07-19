FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends fonts-noto-cjk \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY server ./server
RUN useradd --system --uid 10001 dashboard \
    && mkdir /data \
    && chown dashboard:dashboard /data

USER dashboard
ENV DASHBOARD_DATA_DIR=/data
EXPOSE 8486
CMD ["gunicorn", "--workers=1", "--threads=2", "--bind=0.0.0.0:8486", "--error-logfile=-", "--access-logfile=/dev/null", "--chdir=/app/server", "app:app"]
