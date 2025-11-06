import os
import cv2
from ultralytics import YOLO
from pathlib import Path

# Load a YOLO model from ultralytics (e.g., YOLOv8s)
model = YOLO("yolov8l.pt")  # or use another model like 'yolov8n.pt', 'yolov8m.pt', etc.

# Function to process each video
def process_video(input_path, output_path):
    # Open the video file
    cap = cv2.VideoCapture(input_path)
    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = int(cap.get(cv2.CAP_PROP_FPS))

    # Define the codec and create a VideoWriter for the output
    fourcc = cv2.VideoWriter_fourcc(*'H264')
    out = cv2.VideoWriter(output_path, fourcc, fps, (width, height))

    # Process each frame
    while cap.isOpened():
        ret, frame = cap.read()
        if not ret:
            break

        # Run YOLOv8 on the frame
        results = model(frame)

        # Get the rendered frame with detections
        annotated_frame = results[0].plot()  # This plots detections on the frame

        # Write the annotated frame to the output video
        out.write(annotated_frame)

    # Release resources
    cap.release()
    out.release()

# Main function to process all videos in a folder
def process_videos_in_folder(folder_path, output_folder):
    os.makedirs(output_folder, exist_ok=True)
    for video_file in Path(folder_path).glob("*.mp4"):  # Adjust the extension if needed
        input_path = str(video_file)
        output_path = os.path.join(output_folder, f"output_{video_file.name}")
        print(f"Processing {input_path} -> {output_path}")
        process_video(input_path, output_path)
        print("Done")
    print("Processing complete!")

# Specify folder with videos and output folder
input_folder = "./videos"
output_folder = "./output_videos"
process_videos_in_folder(input_folder, output_folder)
