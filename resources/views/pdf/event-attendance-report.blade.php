<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $event->name }} — Attendance Report</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 10px;
            color: #111;
            margin: 20px;
        }

        h1 {
            font-size: 18px;
            margin-bottom: 4px;
        }

        h2 {
            font-size: 14px;
            margin-top: 24px;
            margin-bottom: 8px;
            border-bottom: 2px solid #333;
            padding-bottom: 4px;
        }

        .subtitle {
            color: #555;
            font-size: 12px;
            margin-bottom: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }

        td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        tr:nth-child(even) {
            background-color: #fafafa;
        }

        .status-checked-in {
            color: #28a745;
            font-weight: bold;
        }

        .status-checked-out {
            color: #17a2b8;
            font-weight: bold;
        }

        .status-no-show,
        .status-absent {
            color: #dc3545;
            font-weight: bold;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }
    </style>
</head>

<body>
    <h1>{{ $event->name }}</h1>
    <div class="subtitle">
        Event Attendance Report — Generated {{ now()->format('Y-m-d H:i') }}
    </div>

    <h2>Attendance Summary by Session</h2>
    <table>
        <thead>
            <tr>
                <th>Session</th>
                <th>Date</th>
                <th class="text-right">Registered</th>
                <th class="text-right">Checked In</th>
                <th class="text-right">Checked Out</th>
                <th class="text-right">Absent</th>
                <th class="text-right">Check-in Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sessionSummaries as $summary)
                <tr>
                    <td>{{ $summary['name'] }}</td>
                    <td>{{ $summary['date'] }}</td>
                    <td class="text-center">{{ $summary['total_registered'] }}</td>
                    <td class="text-center status-checked-in">{{ $summary['checked_in'] }}</td>
                    <td class="text-center status-checked-out">{{ $summary['checked_out'] }}</td>
                    <td class="text-center status-no-show">{{ $summary['no_show'] }}</td>
                    <td class="text-center">{{ $summary['check_in_rate'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($includeDetailedRoster)
        @foreach ($sessionDetailedRosters as $sessionRoster)
            <h2>Detailed Attendance Roster — {{ $sessionRoster['name'] }} ({{ $sessionRoster['date'] }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Union</th>
                        <th>Mission</th>
                        <th>Status</th>
                        <th>Method</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessionRoster['registrations'] as $registration)
                        <tr>
                            <td>{{ $registration['first_name'] }}</td>
                            <td>{{ $registration['last_name'] }}</td>
                            <td>{{ $registration['union'] }}</td>
                            <td>{{ $registration['mission'] }}</td>
                            <td>
                                <span class="status-{{ strtolower(str_replace(' ', '-', $registration['status'])) }}">
                                    {{ $registration['status'] }}
                                </span>
                            </td>
                            <td>{{ ucfirst($registration['method']) }}</td>
                            <td>{{ $registration['check_in_at'] }}</td>
                            <td>{{ $registration['check_out_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @else
        <div class="subtitle" style="margin-top: 20px;">
            For detailed per-attendee records by session, please export the Excel format or request a specific session's
            PDF.
        </div>
    @endif
</body>

</html>
