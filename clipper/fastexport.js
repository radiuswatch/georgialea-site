/* ClipNow fast export: makes a vertical clip much faster than real time.
 * Reads the source MP4/MOV with mp4box.js, decodes frames with the browser's VideoDecoder, draws each one
 * exactly like the real-time exporter (drawFrame: crop or blurred fit, title, progress bar), encodes with
 * VideoEncoder (H.264), copies the original AAC sound as is (no re-encoding), and writes an MP4 with mp4-muxer.
 * Anything it can't handle (no WebCodecs, rotated video, non-AAC sound, fragmented files...) makes
 * prepare() say no, and the Studio uses the real-time exporter instead.
 * Libraries (in /vendor): mp4box.js (BSD-3-Clause), mp4-muxer (MIT).
 */
(function(){
  'use strict';
  var BASE = '/vendor/';
  function loadScript(src){
    return new Promise(function(res, rej){ var s = document.createElement('script'); s.src = src; s.async = true; s.onload = res; s.onerror = function(){ rej(new Error('load ' + src)); }; document.head.appendChild(s); });
  }
  var libs = null;
  function loadLibs(){
    if (!libs) libs = Promise.all([window.MP4Box ? 0 : loadScript(BASE + 'mp4box.all.min.js?v=0.5.3'), window.Mp4Muxer ? 0 : loadScript(BASE + 'mp4-muxer.js?v=5.2.1')]);
    return libs;
  }
  function wait(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }
  function readRange(file, start, end){ return file.slice(start, end).arrayBuffer(); }

  /** Top-level boxes of the file, read from their 8/16-byte headers only (no need to load the video). */
  function findBoxes(file){
    var out = [], pos = 0, guard = 0;
    function next(){
      if (pos + 8 > file.size || ++guard > 200) return Promise.resolve(out);
      return readRange(file, pos, Math.min(file.size, pos + 16)).then(function(b){
        var dv = new DataView(b), size = dv.getUint32(0), type = String.fromCharCode(dv.getUint8(4), dv.getUint8(5), dv.getUint8(6), dv.getUint8(7));
        if (size === 1 && b.byteLength >= 16) size = dv.getUint32(8) * 4294967296 + dv.getUint32(12);
        else if (size === 0) size = file.size - pos;
        if (size < 8) return out;
        out.push({ type:type, start:pos, size:size });
        pos += size;
        return next();
      });
    }
    return next();
  }
  function boxDescription(entry){
    var box = entry.avcC || entry.hvcC || entry.vpcC || entry.av1C;
    if (!box) return undefined;
    var DS = MP4Box.DataStream || window.DataStream;   // a separate global in the browser build
    var ds = new DS(undefined, 0, DS.BIG_ENDIAN);
    box.write(ds);
    return new Uint8Array(ds.buffer, 8);   // without the box header
  }
  function aacConfig(entry){
    try { return new Uint8Array(entry.esds.esd.descs[0].descs[0].data); } catch(e){ return undefined; }
  }
  var IDENTITY = [65536, 0, 0, 0, 65536, 0, 0, 0, 1073741824];

  var state = null;   // { file, video:{...}, audio:{...}|null, enc:{codec} } for the current source

  /** Can this file be exported fast in this browser? Resolves true/false (never throws). */
  var why = '';
  function no(r){ why = r; return false; }
  function prepare(file){
    if (state && state.file === file) return Promise.resolve(true);
    state = null;
    if (!file || !window.VideoDecoder || !window.VideoEncoder || !window.EncodedVideoChunk || !window.VideoFrame) return Promise.resolve(no('no WebCodecs'));
    return loadLibs().then(function(){ return findBoxes(file); }).then(function(boxes){
      var moov = boxes.filter(function(b){ return b.type === 'moov'; })[0];
      if (!moov || boxes.some(function(b){ return b.type === 'moof'; })) return no(moov ? 'fragmented file' : 'no moov box');   // fragmented files: real-time instead
      if (moov.size > 200 * 1048576) return no('index too big');
      // mp4box reads from the start of a file, and the index (moov) is often at the end, after the video data.
      // Give it just the header (ftyp) followed by the index: sample positions in the index are absolute file
      // offsets, so they still point at the right bytes of the real file.
      var ftyp = boxes.filter(function(b){ return b.type === 'ftyp'; })[0];
      return Promise.all([ftyp ? readRange(file, ftyp.start, ftyp.start + ftyp.size) : Promise.resolve(new ArrayBuffer(0)), readRange(file, moov.start, moov.start + moov.size)]).then(function(parts){
        var joined = new Uint8Array(parts[0].byteLength + parts[1].byteLength);
        joined.set(new Uint8Array(parts[0]), 0); joined.set(new Uint8Array(parts[1]), parts[0].byteLength);
        var buf = joined.buffer;
        var mp4 = MP4Box.createFile(), info = null;
        mp4.onReady = function(i){ info = i; };
        mp4.onError = function(){};
        buf.fileStart = 0;
        mp4.appendBuffer(buf); mp4.flush();
        if (!info || !info.videoTracks.length) return no('no video track found');
        var vt = info.videoTracks[0];
        // Phone videos are often stored sideways with a "rotate when playing" note: turn each frame upright (90/180/270).
        var rot = 0;
        if (vt.matrix && Array.prototype.some.call(vt.matrix, function(x, i){ return x !== IDENTITY[i]; })){
          var m = vt.matrix, ang = Math.round(Math.atan2(m[1], m[0]) * 180 / Math.PI);
          rot = ((ang % 360) + 360) % 360;
          if ([90, 180, 270].indexOf(rot) < 0 || Math.abs(Math.hypot(m[0], m[1]) - 65536) > 2 || m[0] !== m[4] || m[1] !== -m[3]) return no('unusual rotation');
        }   // rotated video
        var vtrak = mp4.getTrackById(vt.id), vsamples = mp4.getTrackSamplesInfo(vt.id);
        if (!vsamples || !vsamples.length) return no('no video samples');
        var video = { codec:vt.codec, w:vt.video.width, h:vt.video.height, rot:rot, timescale:vt.timescale, samples:vsamples, description:boxDescription(vtrak.mdia.minf.stbl.stsd.entries[0]) };
        var audio = null;
        if (info.audioTracks.length){
          var at = info.audioTracks[0];
          if (!/^mp4a/.test(at.codec)) return no('sound is ' + at.codec);   // only AAC sound is copied as is
          var atrak = mp4.getTrackById(at.id), asamples = mp4.getTrackSamplesInfo(at.id);
          var desc = aacConfig(atrak.mdia.minf.stbl.stsd.entries[0]);
          if (!asamples || !asamples.length || !desc) return no('no AAC config');
          audio = { codec:at.codec === 'mp4a.40.02' ? 'mp4a.40.2' : at.codec, rate:at.audio.sample_rate, channels:at.audio.channel_count, timescale:at.timescale, samples:asamples, description:desc };
        }
        return VideoDecoder.isConfigSupported({ codec:video.codec, codedWidth:video.w, codedHeight:video.h, description:video.description }).then(function(r){
          if (!r.supported) return no('decoder: ' + video.codec);
          state = { file:file, video:video, audio:audio, enc:{} };
          return true;
        });
      });
    }).catch(function(e){ state = null; return no('error: ' + (e && e.message)); });
  }

  /** An H.264 encoder setting this browser supports for W×H, or null. */
  function pickEncoder(W, H, bitrate){
    var key = W + 'x' + H;
    if (state.enc[key] !== undefined) return Promise.resolve(state.enc[key]);
    var cands = ['avc1.640028', 'avc1.4d0028', 'avc1.42e028'];
    function tryAt(i){
      if (i >= cands.length){ state.enc[key] = null; return null; }
      var cfg = { codec:cands[i], width:W, height:H, bitrate:bitrate, framerate:30, avc:{ format:'avc' } };
      return VideoEncoder.isConfigSupported(cfg).then(function(r){ if (r.supported){ state.enc[key] = r.config || cfg; return state.enc[key]; } return tryAt(i + 1); }, function(){ return tryAt(i + 1); });
    }
    return tryAt(0);
  }

  /** Make one clip. drawFrame(ctx, source, W, H, clip, seconds) is the Studio's own frame painter. */
  function render(clip, H, onProgress, drawFrame){
    if (!state) return Promise.reject(new Error('not prepared'));
    var W = Math.round(H * 9 / 16 / 2) * 2, bitrate = H >= 1080 ? 8e6 : 4.5e6, file = state.file, V = state.video, A = state.audio;
    var startUs = clip.start * 1e6, endUs = clip.end * 1e6;
    return pickEncoder(W, H, bitrate).then(function(cfg){
      if (!cfg) throw new Error('no H.264 encoder');
      var target = new Mp4Muxer.ArrayBufferTarget();
      var muxer = new Mp4Muxer.Muxer({ target:target, video:{ codec:'avc', width:W, height:H }, audio: A ? { codec:'aac', numberOfChannels:A.channels, sampleRate:A.rate } : undefined, fastStart:'in-memory', firstTimestampBehavior:'offset' });
      var cv = document.createElement('canvas'); cv.width = W; cv.height = H;
      var ctx = cv.getContext('2d');
      // upright copy of each frame when the video is stored rotated
      var up = null, upx = null;
      function upright(frame){
        if (!V.rot) return frame;
        var fw = frame.displayWidth, fh = frame.displayHeight, side = V.rot === 90 || V.rot === 270;
        if (!up){ up = document.createElement('canvas'); upx = up.getContext('2d'); }
        var w = side ? fh : fw, h = side ? fw : fh;
        if (up.width !== w || up.height !== h){ up.width = w; up.height = h; }
        upx.setTransform(1, 0, 0, 1, 0, 0);
        upx.translate(w / 2, h / 2); upx.rotate(V.rot * Math.PI / 180);
        upx.drawImage(frame, -fw / 2, -fh / 2, fw, fh);
        return up;
      }
      var failure = null, lastKey = -1e12, framesOut = 0;
      var encoder = new VideoEncoder({ output:function(chunk, meta){ muxer.addVideoChunk(chunk, meta); }, error:function(e){ failure = failure || e; } });
      encoder.configure(cfg);
      var decoder = new VideoDecoder({
        output:function(frame){
          try {
            var ts = frame.timestamp;
            if (failure || ts < startUs - 1000 || ts > endUs){ frame.close(); return; }
            drawFrame(ctx, upright(frame), W, H, clip, ts / 1e6);
            var dur = frame.duration || 33333;
            frame.close();
            var out = new VideoFrame(cv, { timestamp:Math.max(0, ts - startUs), duration:dur });
            var key = ts - lastKey >= 2e6;
            if (key) lastKey = ts;
            encoder.encode(out, { keyFrame:key });
            out.close();
            framesOut++;
            onProgress(Math.min(1, (ts - startUs) / (endUs - startUs)));
          } catch(e){ failure = failure || e; try { frame.close(); } catch(x){} }
        },
        error:function(e){ failure = failure || e; }
      });
      decoder.configure({ codec:V.codec, codedWidth:V.w, codedHeight:V.h, description:V.description, optimizeForLatency:false });

      // Samples to decode: from the keyframe at or before the start, until a little past the end (B-frames)
      var s = V.samples, ts = V.timescale, first = 0, last = s.length - 1;
      for (var i = 0; i < s.length; i++){ if (s[i].cts / ts * 1e6 <= startUs && s[i].is_sync) first = i; if (s[i].dts / ts * 1e6 > endUs + 500000){ last = i; break; } }
      var BATCH = 90;
      function feed(i){
        if (failure) return Promise.reject(failure);
        if (i > last) return Promise.resolve();
        var j = Math.min(last, i + BATCH - 1), lo = Infinity, hi = 0;
        for (var k = i; k <= j; k++){ lo = Math.min(lo, s[k].offset); hi = Math.max(hi, s[k].offset + s[k].size); }
        return readRange(file, lo, hi).then(function(buf){
          var chain = Promise.resolve();
          for (var k2 = i; k2 <= j; k2++) (function(sm){
            chain = chain.then(function(){
              if (failure) throw failure;
              var go = function(){
                decoder.decode(new EncodedVideoChunk({ type:sm.is_sync ? 'key' : 'delta', timestamp:sm.cts / ts * 1e6, duration:sm.duration / ts * 1e6, data:new Uint8Array(buf, sm.offset - lo, sm.size) }));
              };
              // keep memory low: wait while the decoder or encoder has work queued
              if (decoder.decodeQueueSize > 6 || encoder.encodeQueueSize > 6){
                return (function hold(){ return wait(4).then(function(){ if (failure) throw failure; if (decoder.decodeQueueSize > 6 || encoder.encodeQueueSize > 6) return hold(); go(); }); })();
              }
              go();
            });
          })(s[k2]);
          return chain;
        }).then(function(){ return feed(j + 1); });
      }
      function audioPart(){
        if (!A) return Promise.resolve();
        var as = A.samples, ats = A.timescale, pick = [];
        for (var i2 = 0; i2 < as.length; i2++){ var t = as[i2].cts / ats * 1e6; if (t >= startUs && t < endUs) pick.push(as[i2]); }
        if (!pick.length) return Promise.resolve();
        var lo2 = Infinity, hi2 = 0;
        pick.forEach(function(p){ lo2 = Math.min(lo2, p.offset); hi2 = Math.max(hi2, p.offset + p.size); });
        return readRange(file, lo2, hi2).then(function(buf){
          var meta = { decoderConfig:{ codec:A.codec, sampleRate:A.rate, numberOfChannels:A.channels, description:A.description } };
          pick.forEach(function(p, n){
            muxer.addAudioChunkRaw(new Uint8Array(buf, p.offset - lo2, p.size), 'key', p.cts / ats * 1e6 - startUs, p.duration / ats * 1e6, n === 0 ? meta : undefined);
          });
        });
      }
      function cleanup(){ try { if (decoder.state !== 'closed') decoder.close(); } catch(e){} try { if (encoder.state !== 'closed') encoder.close(); } catch(e){} }
      return feed(first).then(function(){ return decoder.flush(); }).then(function(){ return encoder.flush(); }).then(function(){
        if (failure) throw failure;
        if (framesOut < 2) throw new Error('no frames');
        return audioPart();
      }).then(function(){
        muxer.finalize(); cleanup();
        var blob = new Blob([target.buffer], { type:'video/mp4' });
        if (blob.size < 20000) throw new Error('empty');
        onProgress(1);
        return { blob:blob, ext:'mp4', fast:true };
      }, function(e){ cleanup(); throw e; });
    });
  }

  window.FastExport = { prepare:prepare, render:render, why:function(){ return why; } };
})();
