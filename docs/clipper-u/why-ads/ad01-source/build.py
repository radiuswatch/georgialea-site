import json, subprocess, sys, os
from PIL import Image
S, D = sys.argv[1:3]; B = f"{S}/build"; FPS = 30
run = lambda *a: subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', *a], check=True)

# ---------- 1. one moving shot per scene (slow push-ins, the keychain zoom, the end-card settle)
scenes = [  # image, start, end, (zoom from, zoom to), (focus x, focus y)
  ('gen/s1.png',  0.00,  3.00, (1.00, 1.10), (0.50, 0.42)),
  ('gen/s2.png',  3.00,  7.30, (1.00, 1.10), (0.50, 0.55)),
  ('gen/s3.png',  7.30, 10.45, (1.12, 1.00), (0.55, 0.50)),
  ('gfx/s4.png', 10.45, 16.20, (1.00, 1.12), (0.50, 0.40)),
  ('gen/s5.png', 16.20, 19.40, (1.00, 1.10), (0.50, 0.50)),
  ('gen/s6.png', 19.40, 23.00, (1.00, 1.08), (0.60, 0.50)),
  ('gen/s7.png', 23.00, 24.60, None, None),          # push toward the CN tag
  ('gfx/endcard.png', 24.60, 30.00, None, None),     # starts enlarged on the tag's spot, settles
]
XF = [0.30, 0.30, 0.30, 0.30, 0.30, 0.20, 0.45]  # dissolve length into the next scene
segs = []
for i, (img, a, b, zz, ff) in enumerate(scenes):
    dur = (b - a) + (XF[i] if i < len(XF) else 0); n = round(dur * FPS)
    src = f"{S}/{img}"; big = f"{B}/big{i}.png"
    im = Image.open(src).convert('RGB')
    w, h = im.size; th = round(w * 16 / 9)
    if th <= h: im = im.crop((0, (h - th) // 2, w, (h - th) // 2 + th))
    im.resize((2160, 3840), Image.LANCZOS).save(big)
    p = f"(on/{n - 1})"
    if zz:
        z = f"{zz[0]}+({zz[1] - zz[0]})*{p}"; x = f"(iw-iw/zoom)*{ff[0]}"; y = f"(ih-ih/zoom)*{ff[1]}"
    elif i == 6:   # tag at (0.471, 0.655), accelerate in, aim it at (0.50, 0.483) where the end-card logo will start
        z = f"1+0.5*{p}*{p}"
        x = f"max(0,min(iw-iw/zoom, iw*(0.471-0.5/zoom)))"; y = f"max(0,min(ih-ih/zoom, ih*(0.655-0.483/zoom)))"
    else:          # end card: from 1.44x with its logo on the tag's spot, ease out to normal in 0.9 s
        q = f"min(1,on/{round(0.9 * FPS)})"; e = f"(1-pow(1-{q},3))"
        z = f"1.44-0.44*{e}"; oy = f"(0.483-0.117*{e})"
        x = f"max(0,iw*0.5-0.5*iw/zoom)"; y = f"max(0,ih*0.366-{oy}*ih/zoom)"
    out = f"{B}/seg{i}.mp4"; segs.append((out, dur))
    run('-loop', '1', '-i', big, '-vf', f"zoompan=z='{z}':x='{x}':y='{y}':d={n}:s=1080x1920:fps={FPS},format=yuv420p",
        '-frames:v', str(n), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', out)
    print('scene', i + 1, f"{dur:.2f}s")

# ---------- 2. join the scenes with dissolves
inputs = sum((['-i', s] for s, _ in segs), [])
f, last, run_len = '', '[0:v]', segs[0][1]
for k in range(1, len(segs)):
    off = run_len - XF[k - 1]; tag = f"[x{k}]"
    f += f"{last}[{k}:v]xfade=transition=fade:duration={XF[k-1]}:offset={off:.3f}{tag};"; last = tag
    run_len = run_len + segs[k][1] - XF[k - 1]
run(*inputs, '-filter_complex', f.rstrip(';'), '-map', last, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', '-t', '30', f"{B}/joined.mp4")
print('joined', round(run_len, 2), 's')

# ---------- 3. overlays: corner logo, the gold question, GO CLIP, captions
def cropbox(src, dst):
    im = Image.open(src); bb = im.getbbox(); im.crop(bb).save(dst); return bb
tb = cropbox(f"{S}/gfx/title.png", f"{B}/title-c.png"); gb = cropbox(f"{S}/gfx/goclip.png", f"{B}/go-c.png")
V = json.load(open(f"{B}/voice.json")); at = {'t1': 0.35, 'k1': 3.15, 't3': 7.78, 'k2': 16.45, 't5': 19.55}
t3 = [at['t3'] + o for o in V['t3']['phrases']] + [at['t3'] + V['t3']['len']]
t5wait = at['t5'] + V['t5']['phrases'][1]
t5go = at['t5'] + V['t5']['phrases'][2]
def ts(t): return f"{int(t // 3600)}:{int(t % 3600 // 60):02d}:{t % 60:05.2f}"
G, W = r"{\c&H0051C4F5&}", r"{\c&H00FFFFFF&}"
caps = [(3.10, at['k1'] + V['k1']['len'] + 0.1, f"{G}TAVIN JR.: {W}Bro… I don't even\\Nknow how to edit."),
        (t3[0] - 0.05, t3[1], f"{G}T-BUG: {W}You don't have to."), (t3[1], t3[2], "Drop in any video."),
        (t3[2], t3[3], "ClipNow finds\\Nthe best moments,"), (t3[3], t3[4] + 0.15, "and cuts the clips for you."),
        (at['k2'] - 0.05, at['k2'] + V['k2']['len'] + 0.15, f"{G}TAVIN JR.: {W}Wait… that's it?"),
        (at['t5'] - 0.05, t5wait - 0.02, f"{G}T-BUG: {W}That's it."), (t5wait - 0.02, t5go - 0.02, "What are you waiting for?")]
head = open(f"{D}/subs.ass").read().split('[Events]')[0]
with open(f"{B}/subs.ass", 'w') as fh:
    fh.write(head + "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n")
    for a, b, t in caps: fh.write(f"Dialogue: 0,{ts(a)},{ts(b)},Cap,,0,0,0,,{t}\n")
def slam(name, box, t0, t1):  # pops in big, snaps to size, fades out
    cx, cy = (box[0] + box[2]) / 2, (box[1] + box[3]) / 2; w0 = box[2] - box[0]
    sc = f"(1+0.35*max(0,1-(t-{t0})/0.16))"
    return (f"[{name}]format=rgba,scale=w='{w0}*{sc}':h=-1:eval=frame,fade=t=in:st={t0}:d=0.08:alpha=1,fade=t=out:st={t1 - 0.15}:d=0.15:alpha=1[{name}s];",
            f"overlay=x='{cx}-w/2':y='{cy}-h/2':enable='between(t,{t0},{t1})':eval=frame")
s1, o1 = slam('2:v', tb, 0.20, 2.95); s2, o2 = slam('3:v', gb, t5go, 22.95)
fc = (s1.replace('[2:v]', '[2:v]') + s2 +
      f"[0:v][1:v]overlay=0:0:enable='lt(t,24.6)'[a];[a][2:vs]{o1}[b];[b][3:vs]{o2}[c];[c]subtitles={B}/subs.ass:fontsdir=/usr/share/fonts/opentype/inter[v]")
fc = fc.replace('[2:vs]', '[2:vs]').replace('[2:v]format', '[2:v]format')
run('-i', f"{B}/joined.mp4", '-loop', '1', '-i', f"{D}/bug.png", '-loop', '1', '-i', f"{B}/title-c.png", '-loop', '1', '-i', f"{B}/go-c.png",
    '-filter_complex', fc.replace('[2:v]format', '[2:v]format').replace('[2:vs]', '[2:vs]'), '-map', '[v]', '-t', '30',
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', f"{B}/picture.mp4")
print('overlays done')

# ---------- 4. sound: music bed, voices, whoosh on the question, snip + chime on the logo
run('-f', 'lavfi', '-i', 'anoisesrc=d=0.35:c=pink:a=0.6', '-af', "highpass=f=600,lowpass=f=6000,afade=t=in:d=0.12,afade=t=out:st=0.12:d=0.23,volume=0.7", '-ar', '48000', '-ac', '2', f"{B}/whoosh.wav")
run('-f', 'lavfi', '-i', 'anoisesrc=d=0.012:c=white:a=0.9', '-f', 'lavfi', '-i', 'anoisesrc=d=0.012:c=white:a=0.9',
    '-filter_complex', "[0]bandpass=f=4200:width_type=q:w=1.2[a];[1]bandpass=f=3600:width_type=q:w=1.2,adelay=70[b];[a][b]amix=2:normalize=0,volume=1.6", '-ar', '48000', '-ac', '2', f"{B}/snip.wav")
run('-f', 'lavfi', '-i', "aevalsrc='0.35*sin(2*PI*1046.5*t)*exp(-3.2*t)+0.25*sin(2*PI*1568*t)*exp(-3.6*(t-0.12))*gte(t,0.12)':s=48000:d=1.6", '-ac', '2', f"{B}/chime.wav")
ins = ['-i', f"{D}/music.mp3"] + sum((['-i', f"{B}/{k}.wav"] for k in ('t1', 'k1', 't3', 'k2', 't5')), []) + ['-i', f"{B}/whoosh.wav", '-i', f"{B}/snip.wav", '-i', f"{B}/chime.wav"]
starts = [at[k] for k in ('t1', 'k1', 't3', 'k2', 't5')] + [0.12, 24.40, 24.70]
af = "[0]volume='if(lt(t,24.4),0.30,0.30+0.25*min(1,(t-24.4)/0.6))':eval=frame,afade=t=out:st=28.8:d=1.2[m];"
for j, s in enumerate(starts, start=1):
    vol = 1.0 if j <= 5 else 0.8
    af += f"[{j}]aresample=48000,volume={vol},adelay={int(s * 1000)}|{int(s * 1000)}[a{j}];"
af += "[m]" + ''.join(f"[a{j}]" for j in range(1, len(starts) + 1)) + f"amix=inputs={len(starts) + 1}:normalize=0,atrim=0:30,loudnorm=I=-16:TP=-1.5:LRA=11[o]"
run(*ins, '-filter_complex', af, '-map', '[o]', '-ar', '48000', '-ac', '2', f"{B}/mix.wav")
run('-i', f"{B}/picture.mp4", '-i', f"{B}/mix.wav", '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'slow', '-crf', '19', '-pix_fmt', 'yuv420p',
    '-profile:v', 'high', '-movflags', '+faststart', '-c:a', 'aac', '-b:a', '160k', '-t', '30', f"{B}/clipnow-why-ad01.mp4")
print('done')
