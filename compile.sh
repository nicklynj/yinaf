#!/bin/bash

rm -f build/yinaf.js

cat state.js >> build/yinaf.js
cat cache.js >> build/yinaf.js
cat layer.js >> build/yinaf.js
cat component.js >> build/yinaf.js
cat api.js >> build/yinaf.js
cat error.js >> build/yinaf.js
