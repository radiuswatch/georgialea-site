# Tighten each voice line: keep the spoken parts, shorten the pauses between them.
import subprocess, json, sys
S = sys.argv[1]
keep = {  # (start, end) of each spoken phrase in the raw file, measured with silencedetect
  't1': [(0.50, 2.16)],
  'k1': [(0.40, 0.95), (1.70, 2.96), (3.54, 4.80)],
  't3': [(0.41, 1.45), (1.91, 3.35), (3.87, 6.05), (6.45, 8.16)],
  'k2': [(0.42, 0.83), (2.43, 2.93)],
  't5': [(0.74, 1.41), (2.21, 2.96)],
}
gap = {'t1': 0, 'k1': 0.28, 't3': 0.30, 'k2': 0.45, 't5': 0.50}
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
