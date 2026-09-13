<!--[if mso]>
<v:rect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" fill="true" stroke="false" style="width:{{ $width ?? '200px' }};height:{{ $height ?? '44px' }};v-text-anchor:middle;">
    <v:fill type="solid" color="{{ config('branding.colors.primary') }}" />
    <w:anchorlock/>
    <center>
        <![endif]-->
<table border="0" cellspacing="0" cellpadding="0"
    style="border-collapse: separate; width: {{ $width ?? '200px' }}; mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-radius: 6px; background-color: {{ config('branding.colors.primary') }};">
    <tr>
        <td
            style="border-collapse: collapse; border-radius: 6px; padding: 12px 24px; background-color: {{ config('branding.colors.primary') }}; text-align: center;">
            <a href="{{ $url }}"
                style="display: inline-block; width: 100%; background-color: {{ config('branding.colors.primary') }}; color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 16px; font-weight: 600; line-height: 1.5; text-align: center; text-decoration: none; border-radius: 6px; mso-padding-alt: 12px 24px; transition: background-color 0.2s ease;">
                {{ $text }}
            </a>
        </td>
    </tr>
</table>
<!--[if mso]>
    </center>
</v:rect>
<![endif]-->
