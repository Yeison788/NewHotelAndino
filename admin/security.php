<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Surveillance</title>
    <style>
        .video-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr); /* 2 columns */
            gap: 10px;
        }
        .video-grid video {
            width: 100%;
            height: auto;
        }
    </style>
</head>
<body>

<div class="video-grid">
    <?php
    $videos = ["./output_videos/output_no_yolo_v1.mp4", "./output_videos/output_no_yolo_v3.mp4", "./output_videos/output_no_yolo_v2.mp4", "./output_videos/output_no_yolo_v4.mp4"]; // Add your video files here
    foreach ($videos as $video) {
        echo "<video src='$video' autoplay muted loop></video>";
    }
    ?>
</div>

<script>
    document.querySelectorAll('video').forEach(video => {
        video.addEventListener('ended', () => {
            video.currentTime = 0; // Restart from the beginning
            video.play();          // Start playing again
        });
    });
</script>

</body>
</html>
