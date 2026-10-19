# FlowTrack production Redis profile

Use one HA Redis/Tair/Valkey endpoint reachable from every FlowTrack web,
queue, scheduler and Reverb node. Prefer a managed service with automatic
failover rather than a Redis process installed on one web node.

Logical databases used by `deploy/env.horizontal.example`:

- DB 0: generic locks/default Redis
- DB 1: application cache
- DB 2: sessions
- DB 3: queues
- DB 4: Reverb scaling/pub-sub coordination

For Redis Cluster services that expose only DB 0, use separate endpoints or
key prefixes instead of relying on logical databases. Never expose Redis to the
public internet. Use private networking, authentication and TLS when the
provider supports it.
