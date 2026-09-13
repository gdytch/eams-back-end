<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $event->name }} — ID Card Grid</title>
    <style>
        @page {
            margin: 0;
            size: a4;
        }

        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }

        .page {
            width: 210mm;
            padding: 1rem;
            box-sizing: border-box;
            page-break-inside: avoid;
        }

        /* .grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 1rem;
        }

        .card {
            width: 3.25in;
            height: 4.75in;
            text-align: center;
            vertical-align: middle;
            border: 1px solid #ddd;
        }

        .card img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        } */
        img {
            width: 3.25in;
            height: 4.75in;
            margin: 10px;

        }
    </style>
</head>

<body>
    @foreach ($pages as $page)
        <div class="page">
            <table class="grid">
                @foreach (array_chunk($page, 2) as $row)
                    <tr>
                        @foreach ($row as $imagePath)
                            <td class="card">
                                <img src="{{ $imagePath }}" alt="ID card image">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
</body>

</html>
