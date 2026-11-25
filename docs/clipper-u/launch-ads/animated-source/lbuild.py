# Builds one ClipNow launch ad from a timeline: moving scenes with dissolves, corner logo, word pops, captions, music + voice mix.
import json, subprocess, sys
from PIL import Image
S, D, A11, AD = sys.argv[1:5]; FPS = 30
B = f"{S}/build/{AD}"; subprocess.run(['mkdir', '-p', B])
run = lambda *a: subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', *a], check=True)
V = json.load(open(f"{S}/build/voice.json"))
G, W = r"{\c&H0051C4F5&}", r"{\c&H00FFFFFF&}"
ADS = {
 'a': dict(at={'a1': 0.30, 'a2': 3.85, 'a3': 11.25, 'a4': 18.05, 'a5': 20.75}, end_at=26.2, end='end-a.png',
   scenes=[('gen/a1.png', 0, 4.0, (1.0, 1.10), (0.5, 0.45)), ('APP', 4.0, 11.2, (1.0, 1.10), (0.5, 0.0)),
           ('gen/a2.png', 11.2, 17.9, (1.10, 1.0), (0.5, 0.55)), ('gen/a3.png', 17.9, 20.6, (1.0, 1.10), (0.5, 0.45)),
           ('gen/a4.png', 20.6, 25.0, (1.0, 1.08), (0.5, 0.55))],
   slams=[('live.png', ('a1', 1), 4.0), ('tag-a.png', ('a5', 1), 24.95)],
   caps=[(('a1', 0), ('a1', 1), "T-BUG: |Clip Town, it's official…"),
         (('a2', 0), ('a2', 1), "Drop in any long video."), (('a2', 1), ('a2', 2), "ClipNow finds\\Nthe best moments"),
         (('a2', 2), ('a2', 3), "and turns them\\Ninto short clips,"), (('a2', 3), ('a2', 'E'), "with captions."),
         (('a3', 0), ('a3', 1), "Then it posts them\\Nto TikTok,"), (('a3', 1), ('a3', 2), "YouTube,"), (('a3', 2), ('a3', 3), "Instagram,"),
         (('a3', 3), ('a3', 4), "Facebook and X."), (('a3', 4), ('a3', 'E'), "All at once."),
         (('a4', 0), ('a4', 'E'), "And campaigns pay you\\Nfor the views."),
         (('a5', 0), ('a5', 1), "Try it free\\Nfor seven days…")]),
 'b': dict(at={'b1': 0.50, 'b2': 3.90, 'b3': 8.80, 'b4': 15.10, 'b5': 19.70}, end_at=26.2, end='end-b.png',
   scenes=[('gen/b1.png', 0, 3.8, (1.0, 1.08), (0.5, 0.35)), ('gen/b2.png', 3.8, 8.7, (1.0, 1.10), (0.5, 0.5)),
           ('APP', 8.7, 14.85, (1.0, 1.10), (0.5, 0.0)), ('gen/b3.png', 14.85, 19.45, (1.10, 1.0), (0.5, 0.55)),
           ('gen/b4.png', 19.45, 25.0, (1.0, 1.10), (0.5, 0.55))],
   slams=[('live.png', ('b2', 2), 8.65), ('tag-b.png', ('b5', 1), 24.95)],
   caps=[(('b1', 0), ('b1', 2), "T-BUG: |Tick tock, Clip Town."), (('b1', 2), ('b1', 'E'), "You've been waiting…"),
         (('b2', 0), ('b2', 2), "Well, the wait is over."),
         (('b3', 0), ('b3', 1), "Pick a video."), (('b3', 1), ('b3', 2), "ClipNow finds\\Nthe best moments,"),
         (('b3', 2), ('b3', 3), "cuts the clips,"), (('b3', 3), ('b3', 'E'), "and posts them everywhere."),
         (('b4', 0), ('b4', 1), "Campaigns pay\\Nfor the views."), (('b4', 1), ('b4', 'E'), "And your first\\Nseven days are free."),
         (('b5', 0), ('b5', 1), "So what are you\\Nwaiting for?")]),
 'c': dict(at={'c1': 0.40, 'c2': 4.40, 'c3': 6.90, 'c4': 14.40, 'c5': 17.00}, end_at=25.2, end='end-c.png',
   scenes=[('gen/c1.png', 0, 4.2, (1.0, 1.10), (0.5, 0.5)), ('gen/c2.png', 4.2, 8.15, (1.0, 1.12), (0.5, 0.40)),
           ('APP', 8.15, 13.7, (1.0, 1.10), (0.5, 0.0)), ('gen/c3.png', 13.7, 17.2, (1.0, 1.10), (0.5, 0.5)),
           ('gen/c4.png', 17.2, 24.0, (1.0, 1.10), (0.5, 0.55))],
   slams=[('live.png', ('c1', 0), ('c1', 1)), ('tag-c.png', ('c5', 1), 23.95)],
   caps=[(('c1', 1), ('c1', 'E'), "T-BUG: |And if you think clipping\\Nsounds too hard…"),
         (('c2', 0), ('c2', 'E'), "…just ask Dean Townsel."),
         (('c3', 0), ('c3', 1), "She picked a video."), (('c3', 1), ('c3', 2), "ClipNow found\\Nthe best moments,"),
         (('c3', 2), ('c3', 3), "added the captions,"), (('c3', 3), ('c3', 'E'), "and posted them everywhere."),
         (('c4', 0), ('c4', 'E'), "One tap. Done."),
         (('c5', 0), ('c5', 1), "Try it free\\Nfor seven days.")]),
}
cfg = ADS[AD]; at = cfg['at']; E0 = cfg['end_at']; KEY = E0 - 1.2
def T(ref):
    if not isinstance(ref, tuple): return ref
    k, i = ref; return at[k] + (V[k]['len'] if i == 'E' else V[k]['phrases'][i])
scenes = [(f"{S}/gfx/app-cu.png" if s[0] == 'APP' else f"{S}/{s[0]}",) + s[1:] for s in cfg['scenes']]
scenes[-1] = scenes[-1][:2] + (KEY,) + scenes[-1][3:]
scenes += [(f"{A11}/gen/s7.png", KEY, E0, None, None), (f"{S}/gfx/{cfg['end']}", E0, 30.0, None, None)]
XF = [0.30] * (len(scenes) - 3) + [0.20, 0.45]
nkey = len(scenes) - 2
# 1. moving shots
segs = []
for i, (src, a, b, zz, ff) in enumerate(scenes):
    dur = (b - a) + (XF[i] if i < len(XF) else 0); n = round(dur * FPS); big = f"{B}/big{i}.png"
    im = Image.open(src).convert('RGB'); w, h = im.size; th = round(w * 16 / 9)
    if th <= h: im = im.crop((0, (h - th) // 2, w, (h - th) // 2 + th))
    im.resize((2160, 3840), Image.LANCZOS).save(big)
    p = f"(on/{n - 1})"
    if zz: z = f"{zz[0]}+({zz[1] - zz[0]})*{p}"; x = f"(iw-iw/zoom)*{ff[0]}"; y = f"(ih-ih/zoom)*{ff[1]}"
    elif i == nkey:
        z = f"1+0.5*{p}*{p}"; x = "max(0,min(iw-iw/zoom, iw*(0.471-0.5/zoom)))"; y = "max(0,min(ih-ih/zoom, ih*(0.655-0.483/zoom)))"
    else:
        q = f"min(1,on/{round(0.9 * FPS)})"; e = f"(1-pow(1-{q},3))"
        z = f"1.44-0.44*{e}"; oy = f"(0.483-0.117*{e})"; x = "max(0,iw*0.5-0.5*iw/zoom)"; y = f"max(0,ih*0.366-{oy}*ih/zoom)"
    out = f"{B}/seg{i}.mp4"; segs.append((out, dur))
    run('-loop', '1', '-i', big, '-vf', f"zoompan=z='{z}':x='{x}':y='{y}':d={n}:s=1080x1920:fps={FPS},format=yuv420p",
        '-frames:v', str(n), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', out)
print(AD, 'scenes done')
# 2. dissolves
inputs = sum((['-i', s] for s, _ in segs), []); f, last, rl = '', '[0:v]', segs[0][1]
for k in range(1, len(segs)):
    off = rl - XF[k - 1]; tag = f"[x{k}]"; f += f"{last}[{k}:v]xfade=transition=fade:duration={XF[k-1]}:offset={off:.3f}{tag};"; last = tag; rl += segs[k][1] - XF[k - 1]
run(*inputs, '-filter_complex', f.rstrip(';'), '-map', last, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', '-t', '30', f"{B}/joined.mp4")
# 3. overlays
def ts(t): return f"{int(t // 3600)}:{int(t % 3600 // 60):02d}:{t % 60:05.2f}"
head = open(f"{D}/subs.ass").read().split('[Events]')[0]
with open(f"{B}/subs.ass", 'w') as fh:
    fh.write(head + "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n")
    for a, b, t in cfg['caps']:
        if '|' in t: who, t = t.split('|'); t = f"{G}{who}{W}{t}"
        fh.write(f"Dialogue: 0,{ts(T(a) - 0.05)},{ts(T(b) - 0.03 + (0.15 if b[1] == 'E' else 0))},Cap,,0,0,0,,{t}\n")
ins, fc, prev = ['-i', f"{B}/joined.mp4", '-loop', '1', '-i', f"{D}/bug.png"], '', '[0:v][1:v]overlay=0:0:enable=' + f"'lt(t,{E0})'" + '[v0];'
fc += prev; cur = '[v0]'; whoosh = []
for j, (img, t0, t1) in enumerate(cfg['slams']):
    t0, t1 = T(t0), T(t1); whoosh.append(t0)
    im = Image.open(f"{S}/gfx/{img}"); bb = im.getbbox(); im.crop(bb).save(f"{B}/sl{j}.png")
    ins += ['-loop', '1', '-i', f"{B}/sl{j}.png"]; n = j + 2
    cx, cy = (bb[0] + bb[2]) / 2, (bb[1] + bb[3]) / 2; w0 = bb[2] - bb[0]; sc = f"(1+0.35*max(0,1-(t-{t0})/0.16))"
    fc += (f"[{n}:v]format=rgba,scale=w='{w0}*{sc}':h=-1:eval=frame,fade=t=in:st={t0}:d=0.08:alpha=1,fade=t=out:st={t1 - 0.15}:d=0.15:alpha=1[s{j}];"
           f"{cur}[s{j}]overlay=x='{cx}-w/2':y='{cy}-h/2':enable='between(t,{t0},{t1})':eval=frame[v{j + 1}];")
    cur = f"[v{j + 1}]"
fc += f"{cur}subtitles={B}/subs.ass:fontsdir=/usr/share/fonts/opentype/inter[v]"
run(*ins, '-filter_complex', fc, '-map', '[v]', '-t', '30', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', f"{B}/picture.mp4")
print(AD, 'overlays done')
# 4. sound
run('-f', 'lavfi', '-i', 'anoisesrc=d=0.35:c=pink:a=0.6', '-af', "highpass=f=600,lowpass=f=6000,afade=t=in:d=0.12,afade=t=out:st=0.12:d=0.23,volume=0.7", '-ar', '48000', '-ac', '2', f"{B}/whoosh.wav")
run('-f', 'lavfi', '-i', 'anoisesrc=d=0.012:c=white:a=0.9', '-f', 'lavfi', '-i', 'anoisesrc=d=0.012:c=white:a=0.9',
    '-filter_complex', "[0]bandpass=f=4200:width_type=q:w=1.2[a];[1]bandpass=f=3600:width_type=q:w=1.2,adelay=70[b];[a][b]amix=2:normalize=0,volume=1.6", '-ar', '48000', '-ac', '2', f"{B}/snip.wav")
run('-f', 'lavfi', '-i', "aevalsrc='0.35*sin(2*PI*1046.5*t)*exp(-3.2*t)+0.25*sin(2*PI*1568*t)*exp(-3.6*(t-0.12))*gte(t,0.12)':s=48000:d=1.6", '-ac', '2', f"{B}/chime.wav")
keys = list(at)
ins = ['-i', f"{D}/music.mp3"] + sum((['-i', f"{S}/build/{k}.wav"] for k in keys), []) + sum((['-i', f"{B}/whoosh.wav"] for _ in whoosh), []) + ['-i', f"{B}/snip.wav", '-i', f"{B}/chime.wav"]
starts = [at[k] for k in keys] + [w - 0.15 for w in whoosh] + [E0 - 0.2, E0 + 0.1]
af = f"[0]volume='if(lt(t,{E0 - 0.2}),0.30,0.30+0.25*min(1,(t-{E0 - 0.2})/0.6))':eval=frame,afade=t=out:st=28.8:d=1.2[m];"
for j, s in enumerate(starts, start=1):
    af += f"[{j}]aresample=48000,volume={1.0 if j <= len(keys) else 0.8},adelay={int(s * 1000)}|{int(s * 1000)}[a{j}];"
af += "[m]" + ''.join(f"[a{j}]" for j in range(1, len(starts) + 1)) + f"amix=inputs={len(starts) + 1}:normalize=0,atrim=0:30,loudnorm=I=-16:TP=-1.5:LRA=11[o]"
run(*ins, '-filter_complex', af, '-map', '[o]', '-ar', '48000', '-ac', '2', f"{B}/mix.wav")
run('-i', f"{B}/picture.mp4", '-i', f"{B}/mix.wav", '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'slow', '-crf', '19', '-pix_fmt', 'yuv420p',
    '-profile:v', 'high', '-movflags', '+faststart', '-c:a', 'aac', '-b:a', '160k', '-t', '30', f"{S}/build/clipnow-live-ad0{'abc'.index(AD) + 1}.mp4")
print(AD, 'done')
