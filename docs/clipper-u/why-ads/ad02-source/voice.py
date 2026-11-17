# T-Bug = Benji (owner's choice, Nov 17 2026). Tighten each voice line: keep the spoken parts, shorten the pauses between them.
import subprocess, json, sys
S = sys.argv[1]
keep = {  # spoken phrases in each raw take (measured); repeats and stray sounds left out
  'd1': [(0.28,1.24),(2.04,3.77)],
  't1': [(0.42,1.92),(2.58,3.50)],
  'd2': [(0.38,0.91),(1.67,2.37),(3.31,4.15)],
  't2': [(0.46, 0.92), (1.69, 2.43), (3.09, 5.05), (5.36, 6.29), (6.55, 7.53), (7.66, 8.22), (9.29, 10.89)],
  'd3': [(0.35,0.90),(1.80,3.06)],
  't3': [(0.40, 1.94), (2.73, 3.61), (3.99, 4.73)],   # the take said 'Go clip now' twice; keep it once
}
gap = {'d1':0.30,'t1':0.25,'d2':0.25,'t2':0.13,'d3':0.30,'t3':0.25}
tempo = {}  # Dre's lines are cloned from the owner's audition pick; natural pace
out = {}
for name, segs in keep.items():
    parts, filt, t, starts = [], '', 0.0, []
    for i, (a, b) in enumerate(segs):
        filt += f"[0]atrim={a}:{b},asetpts=PTS-STARTPTS,afade=t=in:d=0.03,afade=t=out:st={b-a-0.04:.3f}:d=0.04[s{i}];"
        starts.append(round(t, 3)); t += (b - a)
        if i < len(segs) - 1:
            filt += f"anullsrc=r=24000:cl=mono,atrim=0:{gap[name]}[g{i}];"; t += gap[name]
    chain = ''.join(f"[s{i}]" + (f"[g{i}]" if i < len(segs) - 1 else '') for i in range(len(segs)))
    n = 2 * len(segs) - 1
    filt += f"{chain}concat=n={n}:v=0:a=1,atempo={tempo.get(name,1.0)},aresample=48000,pan=stereo|c0=c0|c1=c0[o]"
    subprocess.run(['ffmpeg','-loglevel','error','-y','-i',f"{S}/gen/{name}.wav",'-filter_complex',filt,'-map','[o]',f"{S}/build/{name}.wav"], check=True)
    k = tempo.get(name, 1.0); out[name] = {'len': round(t / k, 3), 'phrases': [round(x / k, 3) for x in starts]}
json.dump(out, open(f"{S}/build/voice.json", 'w'), indent=1); print(json.dumps(out))
