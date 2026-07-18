# Traverse a namespace directory (no legacy/__init__.py) before reaching the
# nested storage module.
from ..legacy import storage as storage
