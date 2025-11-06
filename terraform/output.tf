output "public_ip" {
  description = "Public IP of the EC2 instance"
  value       = aws_instance.hotelandino_ec2.public_ip
}

output "instance_id" {
  description = "EC2 instance id"
  value       = aws_instance.hotelandino_ec2.id
}

output "security_group_id" {
  description = "Security group id created for the instance"
  value       = aws_security_group.hotelandino_sg.id
}