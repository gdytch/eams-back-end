<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml"
    xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title>{{ $title ?? 'Event Management System' }}</title>
    <style type="text/css">
        body,
        table,
        td,
        div,
        p,
        a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            margin: 0;
            padding: 0;
        }

        table,
        td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        img {
            border: 0;
            display: block;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: nearest-neighbor;
        }

        a[x-apple-data-detectors] {
            color: inherit !important;
            text-decoration: none !important;
            font-size: inherit !important;
            font-family: inherit !important;
            font-weight: inherit !important;
            line-height: inherit !important;
        }

        .preheader {
            display: none;
            max-height: 0;
            overflow: hidden;
        }

        .container-inner {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .button-container {
            mso-padding-alt: 12px 0;
        }

        .button {
            background-color: {{ config('branding.colors.primary') }};
            border-radius: 6px;
            color: #ffffff;
            display: inline-block;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            font-weight: 600;
            line-height: 1.5;
            padding: 12px 24px;
            text-align: center;
            text-decoration: none;
        }

        .button:hover {
            background-color: {{ config('branding.colors.primary_dark') }};
        }

        .header {
            background-color: {{ config('branding.colors.primary_dark') }};
            padding: 24px;
            text-align: center;
        }

        .header-logo {
            max-width: 120px;
            height: auto;
        }

        .body-content {
            padding: 32px;
        }

        .footer-content {
            background-color: {{ config('branding.colors.bg_light') }};
            border-top: 1px solid {{ config('branding.colors.border') }};
            color: {{ config('branding.colors.muted') }};
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
            line-height: 1.6;
            padding: 24px;
            text-align: center;
        }

        .footer-link {
            color: {{ config('branding.colors.primary') }};
            text-decoration: none;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        .text-muted {
            color: {{ config('branding.colors.muted') }};
            font-size: 14px;
            line-height: 1.6;
        }

        .text-primary {
            color: {{ config('branding.colors.primary') }};
            font-weight: 600;
        }

        h1,
        h2,
        h3 {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-weight: 600;
            line-height: 1.4;
            margin: 0 0 16px 0;
        }

        h1 {
            color: {{ config('branding.colors.primary_dark') }};
            font-size: 28px;
        }

        h2 {
            color: {{ config('branding.colors.primary_dark') }};
            font-size: 20px;
        }

        h3 {
            color: {{ config('branding.colors.text') }};
            font-size: 16px;
        }

        p {
            color: {{ config('branding.colors.text') }};
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            margin: 0 0 16px 0;
        }

        .info-row {
            border-bottom: 1px solid {{ config('branding.colors.border') }};
            padding: 12px 0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: {{ config('branding.colors.muted') }};
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            color: {{ config('branding.colors.text') }};
            font-size: 16px;
            font-weight: 500;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <!-- Preheader text (hidden on display) -->
    <div class="preheader">{{ $preheader ?? '' }}</div>

    <!-- Main container: 600px centered table -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0"
        style="background-color: #f3f4f6; padding: 24px 0;">
        <tr>
            <td align="center" style="padding: 0;">
                <table width="600" border="0" cellpadding="0" cellspacing="0"
                    style="width: 100%; max-width: 600px; background-color: #ffffff; border-collapse: collapse;"
                    class="container-inner">
                    <!-- Header -->
                    <tr>
                        <td class="header"
                            style="background-color: {{ config('branding.colors.primary_dark') }}; padding: 24px; text-align: center; border-radius: 8px 8px 0 0;">
                            <a href="{{ config('app.url') }}" style="text-decoration: none;">
                                <img src="cid:logo" alt="Event Management System" class="header-logo" width="120"
                                    style="max-width: 120px; height: auto;" />
                            </a>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="body-content"
                            style="padding: 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
                            {{ $content ?? $slot }}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer-content"
                            style="background-color: {{ config('branding.colors.bg_light') }}; border-top: 1px solid {{ config('branding.colors.border') }}; color: {{ config('branding.colors.muted') }}; font-size: 13px; line-height: 1.6; padding: 24px; text-align: center; border-radius: 0 0 8px 8px;">
                            <p
                                style="margin: 0 0 8px 0; font-size: 13px; color: {{ config('branding.colors.muted') }};">
                                {!! config('branding.footer_text') !!}
                            </p>
                            <p style="margin: 0 0 8px 0; font-size: 13px;">
                                <a href="mailto:{{ config('branding.support_email') }}" class="footer-link"
                                    style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">{{ config('branding.support_email') }}</a>
                            </p>
                            {{ $footer_note ?? '' }}
                            <p
                                style="margin: 16px 0 0 0; font-size: 12px; color: {{ config('branding.colors.muted') }};">
                                You received this email because you have an account on our Event Management System.
                                <br />If you did not initiate this request, please contact <a
                                    href="mailto:{{ config('branding.support_email') }}" class="footer-link"
                                    style="color: {{ config('branding.colors.primary') }}; text-decoration: none;">support</a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!--[if mso]>
    <o:shapetype id="x_main_type" coordsize="21600,21600" o:allowincell="f" opacity=".5">
        <v:f eqn="sum(#0,1)"/>
        <o:f name="adjRadius" value="10800"/>
        <o:lock xmlns:o="urn:schemas-microsoft-com:office:office" text="of" shapetype="f" v:ext="edit" noChangeArrowheads="t"/>
        <v:textpath style="font-family:'Calibri'" o:allowincell="f"/>
        <o:pre v:ext="edit" connecttype="custom" o:connectlocs="10800,0;21600,10800;10800,21600;0,10800" o:connectangles="270,0,90,180"/>
    </o:shapetype>
    <![endif]-->
</body>

</html>
