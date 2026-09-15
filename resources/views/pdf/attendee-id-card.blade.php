<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0;
        }

        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            font-family: sans-serif;
            border: 1px solid #000;
        }

        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .background img {
            width: 100%;
            height: 100%;
        }

        .content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
        }

        .qr {
            margin-top: 215px;

        }

        .qr img {
            width: 110px;
            height: 110px;
            border: 1rem solid #fff;
        }

        .name {
            margin-top: 10px;
            padding: 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        .territory {
            margin-top: 5px;
            padding: 0 8px;
            font-size: 10px;
        }
    </style>
</head>

<body>
    @if ($backgroundImage)
        <div class="background"><img src="{{ $backgroundImage }}" alt=""></div>
    @endif
    <div class="content">
        <div class="qr"><img src="{{ $qrImage }}" alt="QR code"></div>
        <div class="name" style="color: {{ $fontColor }}">{{ $attendeeName }}</div>
        <div class="territory" style="color: {{ $fontColor }}">{{ $organizationName }}</div>
    </div>
</body>

</html>
