# T-Bug = Benji (owner's choice, Nov 17 2026). Tighten each voice line: keep the spoken parts, shorten the pauses between them.
import subprocess, json, sys
S = sys.argv[1]
keep = {  # spoken phrases in each raw take (measured); repeats and stray sounds left out
  'd1': [(0.56, 1.73), (3.49, 4.80)],
  't1': [(0.44, 2.27), (2.75, 3.92)],
  'd2': [(0.21, 0.78), (1.07, 2.51)],
  't2': [(0.46, 0.92), (1.69, 2.43), (3.09, 5.05), (5.36, 6.29), (6.55, 7.53), (7.66, 8.22), (9.29, 10.89)],
  'd3': [(0.37, 0.76), (1.05, 2.72)],
  't3': [(0.40, 1.94), (2.73, 3.61), (3.99, 4.73)],   # the take said 'Go clip now' twice; keep it once
}
gap = {'d1': 0.35, 't1': 0.30, 'd2': 0.25, 't2': 0.18, 'd3': 0.25, 't3': 0.30}
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
    filt += f"{chain}concat=n={n}:v=0:a=1,aresample=48000,pan=stereo|c0=c0|c1=c0[o]"
    subprocess.run(['ffmpeg','-loglevel','error','-y','-i',f"{S}/gen/{name}.wav",'-filter_complex',filt,'-map','[o]',f"{S}/build/{name}.wav"], check=True)
    out[name] = {'len': round(t, 3), 'phrases': starts}
json.dump(out, open(f"{S}/build/voice.json", 'w'), indent=1); print(json.dumps(out))
