import os
import tempfile


# server.app creates the production WSGI application on import. Tests supply a
# harmless, isolated default configuration and create their own app instances.
os.environ.setdefault("DASHBOARD_DEVICES_JSON", '[{"id":"default","token":"default-token","frame_width":800,"frame_height":600,"layout":"landscape","rotate":0}]')
os.environ.setdefault("DASHBOARD_INGEST_TOKEN", "default-ingest-token")
os.environ.setdefault("DASHBOARD_DATA_DIR", tempfile.mkdtemp(prefix="remote-eink-dashboard-"))
