#!/bin/bash

rm -f build/yinaf.js

cat envoy.js >> build/yinaf.js
cat state.js >> build/yinaf.js
cat cache.js >> build/yinaf.js
cat layer.js >> build/yinaf.js
cat component.js >> build/yinaf.js
cat api.js >> build/yinaf.js
cat exception.js >> build/yinaf.js
