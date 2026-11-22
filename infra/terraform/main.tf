# FanFolio infrastructure skeleton. Cloud host and region are not confirmed (CLAUDE.md open
# decisions), so only provider-agnostic inputs exist here. Modules land per milestone:
# network, database (Postgres 17: 1 primary + 2 replicas), redis, cluster (Kubernetes or ECS),
# storage (Cloudflare R2: private ingest + media buckets), cdn (Cloudflare WAF, Workers).

terraform {
  required_version = ">= 1.9"
}

variable "environment" {
  description = "Deployment environment."
  type        = string

  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be staging or production."
  }
}

variable "region" {
  description = "Hosting region. Not confirmed yet: set per environment, never hard-coded."
  type        = string
}

output "environment" {
  value = var.environment
}
