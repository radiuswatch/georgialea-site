#!/bin/bash
# usage: render.sh <in.mp4> <endcard-start-seconds> <out.mp4>
B=$(dirname "$0"); T=$(python3 -c "print(max(0, $2 - 0.2))")
ffmpeg -loglevel error -y -i "$1" -loop 1 -framerate 30 -i "$B/link-in-bio.png" -filter_complex \
 "[1:v]format=rgba,fade=t=in:st=$T:d=0.35:alpha=1[b];[0:v][b]overlay=x=0:y='150+7*sin(2*PI*1.2*(t-$T))':enable='gte(t,$T)':shortest=1,format=yuv420p[v]" \
 -map '[v]' -map 0:a -c:v libx264 -preset medium -crf 19 -profile:v high -r 30 -c:a copy -movflags +faststart "$3" && echo "done $(basename "$3")"
