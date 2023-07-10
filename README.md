# windows_ssh_enhanced
This is a modified version of the original NagiosXI Windows SSH Wizard

07/08/2023 - 2.1.0
===========================
Fixed host check monitoring bug
-- OBJECT TYPE SET HOST vs. SERVICE
--- Resolved the missing template for host check error when applying
Add xiwizard_windows_host_icmp template
Changed CPU Usage to Windows CPU Load
Format changes for CPU Utilization
Changed Disk Usage to be Disk IO
Stage2 metric display format changes

-SN

07/06/2023 - 2.0.0
===========================
Add CPU Utilization Service Check
-- Check Windows Performance Counters for Processor Utilization
--- Add check_cpu_utilization.ps1.py plugin
--- Add check_cpu_utilization_ssh command definition
--- Add xiwizard_windows_server_icmp template

Changed the templates assigned to the service checks created to use the xiwizard templates.
-- Applys NagiosXI Timing, Counts and Retrys
-- Uses XI Time Periods
-- Uses Windows Server dedicated templates

Modified wizard web interface text content
-- Add description of host checks

- SN


06/26/2023 - 1.0.0
===========================
Initial Release [GL:XI#117] - SNS, MB
