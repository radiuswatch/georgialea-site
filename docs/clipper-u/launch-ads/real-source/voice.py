# Trim each T-Bug line to its spoken phrases with short natural pauses (the cloned takes hold long silences at "...").
import subprocess, json, sys, re
S = sys.argv[1]
def chunks(f):
    o = subprocess.run(['ffmpeg','-i',f,'-af','silencedetect=n=-40dB:d=0.2','-f','null','-'],capture_output=True,text=True).stderr
    s=[float(x) for x in re.findall(r'silence_start: ([\d.]+)',o)]; e=[float(x) for x in re.findall(r'silence_end: ([\d.]+)',o)]
    dur=float(subprocess.run(['ffprobe','-v','error','-show_entries','format=duration','-of','csv=p=0',f],capture_output=True,text=True).stdout)
    b=[0.0]+e; en=s+[dur]
    if s and s[0]<0.01: b=e; en=s[1:]+[dur]
    return [(max(0,x-0.06),min(dur,y+0.08)) for x,y in zip(b,en) if y-x>0.12]
GAP = 0.30
out = {}
for k in sys.argv[2:]:
    segs = chunks(f"{S}/gen/{k}.wav"); filt=''; t=0.0; starts=[]
    for i,(a,b) in enumerate(segs):
        filt += f"[0]atrim={a:.3f}:{b:.3f},asetpts=PTS-STARTPTS,afade=t=in:d=0.03,afade=t=out:st={b-a-0.05:.3f}:d=0.05[s{i}];"
        starts.append(round(t,3)); t += b-a
        if i < len(segs)-1: filt += f"anullsrc=r=24000:cl=mono,atrim=0:{GAP}[g{i}];"; t += GAP
    chain=''.join(f"[s{i}]"+(f"[g{i}]" if i<len(segs)-1 else '') for i in range(len(segs)))
    filt += f"{chain}concat=n={2*len(segs)-1}:v=0:a=1,aresample=48000,pan=stereo|c0=c0|c1=c0[o]"
    subprocess.run(['ffmpeg','-loglevel','error','-y','-i',f"{S}/gen/{k}.wav",'-filter_complex',filt,'-map','[o]',f"{S}/build/{k}.wav"],check=True)
    out[k]={'len':round(t,3),'phrases':starts}
json.dump(out, open(f"{S}/build/voice.json",'w'), indent=1)
for k,v in out.items(): print(k, v)
print('totals:', {a: round(sum(v['len'] for k,v in out.items() if k[0]==a),2) for a in 'abc'})
