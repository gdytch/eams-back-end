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

        .status-no-show {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <h1>{{ $event->name }}</h1>
    <div class="subtitle">
        Event Attendance Report — Generated {{ now()->format('Y-m-d H:i') }}
    </div>

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
            @foreach ($registrations as $registration)
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

    <div class="subtitle">
        Total Registrations: {{ $registrations->count() }}
    </div>
</body>

</html>
