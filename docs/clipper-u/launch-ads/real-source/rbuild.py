# Builds one ClipNow launch ad from a timeline: moving scenes with dissolves, corner logo, word pops, captions, music + voice mix.
import json, subprocess, sys
from PIL import Image
S, D, A11, AD, GFX = sys.argv[1:6]; FPS = 30
B = f"{S}/build/{AD}"; subprocess.run(['mkdir', '-p', B])
run = lambda *a: subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', *a], check=True)
V = json.load(open(f"{S}/build/voice.json"))
G, W = r"{\c&H0051C4F5&}", r"{\c&H00FFFFFF&}"
ADS = {
 'a': dict(at={'e1': 0.40, 'e2': 5.00, 'e3': 9.10, 'e4': 17.00}, end_at=25.0, end='end-a.png', out=4,
   scenes=[('vid/r1.mp4', 0, 4.8, 0), ('vid/r2.mp4', 4.8, 9.0, 0), ('gfx/app-r.png', 9.0, 13.8, (1.0, 1.10), (0.5, 0.0)),
           ('vid/r3.mp4', 13.8, 18.6, 0), ('vid/r4.mp4', 18.6, 23.8, 0)],
   slams=[('live.png', ('e2', 1), 8.95), ('tag-a.png', ('e4', 2), 23.75)],
   caps=[(('e1', 0), ('e1', 1), "You scroll all day."), (('e1', 1), ('e1', 'E'), "Your phone is always\\Nin your hand."),
         (('e2', 0), ('e2', 1), "Now it can pay you back."),
         (('e3', 0), ('e3', 1), "Pick a video."), (('e3', 1), ('e3', 2), "ClipNow finds\\Nthe best moments,"), (('e3', 2), ('e3', 3), "adds captions,"),
         (('e3', 3), ('e3', 'E'), "and posts your clips\\Neverywhere at once."),
         (('e4', 0), ('e4', 1), "Campaigns pay\\Nfor the views."), (('e4', 1), ('e4', 2), "Try it free\\Nfor seven days…")]),
 'b': dict(at={'f1': 0.50, 'f2': 5.20, 'f3': 8.90, 'f4': 15.60}, end_at=24.6, end='end-b.png', out=5,
   scenes=[('vid/s1.mp4', 0, 2.0, 0), ('vid/s2.mp4', 2.0, 3.4, 0), ('vid/s3.mp4', 3.4, 5.0, 0), ('vid/s2.mp4', 5.0, 8.6, 1.4),
           ('gfx/app-s.png', 8.6, 14.8, (1.0, 1.10), (0.5, 0.0)), ('vid/s3.mp4', 14.8, 17.7, 1.7), ('vid/s4.mp4', 17.7, 23.4, 0)],
   slams=[('live.png', ('f3', 0), ('f3', 1)), ('tag-b.png', ('f4', 1), 23.35)],
   caps=[(('f1', 0), ('f1', 1), "Waiting for the bus."), (('f1', 1), ('f1', 2), "On your break."), (('f1', 2), ('f1', 'E'), "Between orders."),
         (('f2', 0), ('f2', 1), "Those minutes add up."), (('f2', 1), ('f2', 'E'), "And now, they can pay."),
         (('f3', 1), ('f3', 2), "It turns long videos\\Ninto short clips,"), (('f3', 2), ('f3', 'E'), "and posts them for you."),
         (('f4', 0), ('f4', 1), "Your first seven days\\Nare free.")]),
 'c': dict(at={'g1': 0.40, 'g2': 4.30, 'g3': 8.60, 'g4': 17.60}, end_at=24.8, end='end-c.png', out=6,
   scenes=[('vid/t1.mp4', 0, 4.4, 0), ('vid/t2.mp4', 4.4, 8.4, 0), ('gfx/app-t.png', 8.4, 14.5, (1.0, 1.10), (0.5, 0.0)),
           ('vid/t3.mp4', 14.5, 17.3, 0), ('vid/t4.mp4', 17.3, 23.6, 0)],
   slams=[('live.png', ('g2', 0), ('g2', 1)), ('tag-c.png', ('g4', 1), 23.55)],
   caps=[(('g1', 0), ('g1', 'E'), "Think clipping is only\\Nfor tech wizards?"),
         (('g2', 1), ('g2', 'E'), "And it does the hard part\\Nfor you."),
         (('g3', 0), ('g3', 1), "Pick a video."), (('g3', 1), ('g3', 2), "It finds the best moments,"), (('g3', 2), ('g3', 3), "cuts the clips,"),
         (('g3', 3), ('g3', 4), "adds captions,"), (('g3', 4), ('g3', 5), "and posts them everywhere."), (('g3', 5), ('g3', 'E'), "One tap."),
         (('g4', 0), ('g4', 1), "Try it free\\Nfor seven days.")]),
}
cfg = ADS[AD]; at = cfg['at']; E0 = cfg['end_at']; KEY = E0 - 1.2
def T(ref):
    if not isinstance(ref, tuple): return ref
    k, i = ref; return at[k] + (V[k]['len'] if i == 'E' else V[k]['phrases'][i])
scenes = [(f"{S}/{x[0]}",) + tuple(x[1:]) + ((None,) if len(x) == 4 else ()) for x in cfg['scenes']]
scenes[-1] = (scenes[-1][0], scenes[-1][1], KEY) + tuple(scenes[-1][3:])
scenes += [(f"{A11}/gen/s7.png", KEY, E0, None, None), (f"{GFX}/{cfg['end']}", E0, 30.0, None, None)]
XF = [0.30] * (len(scenes) - 3) + [0.20, 0.45]
nkey = len(scenes) - 2
# 1. moving shots
segs = []
for i, (src, a, b, zz, ff) in enumerate(scenes):
    dur = (b - a) + (XF[i] if i < len(XF) else 0); n = round(dur * FPS); big = f"{B}/big{i}.png"
    if src.endswith('.mp4'):   # a real video shot: start at its offset, slow it slightly if the scene runs longer than the shot
        off = zz or 0; have = float(subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', src], capture_output=True, text=True).stdout) - off
        k = max(1.0, dur / (have - 0.05)); out = f"{B}/seg{i}.mp4"; segs.append((out, dur))
        run('-ss', str(off), '-i', src, '-vf', f"setpts={k:.4f}*(PTS-STARTPTS),scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps={FPS},format=yuv420p",
            '-frames:v', str(n), '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', out)
        continue
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
        fh.write(f"Dialogue: 0,{ts(T(a) - 0.05)},{ts(T(b) - 0.03 + (0.15 if b[1] == 'E' else 0))},Cap,,0,0,0,,{t}\n")
ins, fc, prev = ['-i', f"{B}/joined.mp4", '-loop', '1', '-i', f"{D}/bug.png"], '', '[0:v][1:v]overlay=0:0:enable=' + f"'lt(t,{E0})'" + '[v0];'
fc += prev; cur = '[v0]'; whoosh = []
for j, (img, t0, t1) in enumerate(cfg['slams']):
    t0, t1 = T(t0), T(t1); whoosh.append(t0)
    im = Image.open(f"{GFX}/{img}"); bb = im.getbbox(); im.crop(bb).save(f"{B}/sl{j}.png")
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
    '-profile:v', 'high', '-movflags', '+faststart', '-c:a', 'aac', '-b:a', '160k', '-t', '30', f"{S}/build/clipnow-live-ad0{cfg['out']}.mp4")
print(AD, 'done')
