HERO VIDEO — drop your files here
=================================

The homepage hero looks for these two files:

    assets/videos/hero.webm      (preferred - smaller)
    assets/videos/hero.mp4       (fallback - universal support)

Until you add them, the hero shows assets/images/hero-bg.jpg instead.
The site works fine without them; nothing breaks.

RECOMMENDED SETTINGS
--------------------
Length      8-15 seconds, seamless loop
Resolution  1920x1080 (1080p) - do not go higher, it is a background
Frame rate  24-30fps
Audio       REMOVE IT. The video is muted; audio is dead weight.
File size   Aim under 4MB. Over 8MB will hurt page speed badly on
            Kenyan mobile connections.

ENCODING (ffmpeg)
-----------------
From a source file called source.mp4:

  # MP4 (H.264)
  ffmpeg -i source.mp4 -an -vf "scale=1920:-2,fps=25" \
    -c:v libx264 -crf 28 -preset slow -movflags +faststart hero.mp4

  # WebM (VP9) - usually 30-40% smaller
  ffmpeg -i source.mp4 -an -vf "scale=1920:-2,fps=25" \
    -c:v libvpx-vp9 -crf 36 -b:v 0 hero.webm

The -an flag strips audio. -movflags +faststart lets the MP4 start
playing before it has fully downloaded.

NO FFMPEG? Use CloudConvert or Handbrake with similar settings.

BEHAVIOUR
---------
The video is muted, looped, autoplaying and plays inline. It is hidden
from screen readers. It will NOT load when:

  - the visitor has "reduce motion" enabled in their OS
  - the browser reports Data Saver mode
  - the files are missing or fail to load
  - nothing has loaded after 6 seconds

In all those cases the poster image shows instead.
