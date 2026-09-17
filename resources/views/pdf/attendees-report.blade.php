<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Attendees Report</title>
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

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }
    </style>
</head>

<body>
    <h1>Attendees Report</h1>
    <div class="subtitle">
        {{ $eventName ? "Event: {$eventName} — " : '' }}Total: {{ count($attendees) }}
        attendee{{ count($attendees) !== 1 ? 's' : '' }} — Generated {{ now()->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 1%">#</th>
                <th>Name</th>
                <th>Organization</th>
                <th>Mobile No.</th>
                <th>Email Address</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($attendees as $attendee)
                <tr>
                    <td class="text-center">{{ $loop->iteration }}</td>
                    <td> {{ $attendee->last_name }}, {{ $attendee->first_name }} {{ $attendee->middle_name ?? '' }}</td>
                    <td>
                        {{ $attendee->organization_name }}
                    </td>
                    <td>{{ $attendee->mobile_no ?? '' }}</td>
                    <td>{{ $attendee->email_address ?? '' }}</td>
                    <td>{{ $attendee->remarks ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
