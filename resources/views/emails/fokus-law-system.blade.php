<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>{{ $title }}</title>
  <style>
    @font-face{font-family:'Bebas Neue';src:url('https://fokuscloud.com.br/assets/fonts/google/bebas-neue-original.woff2') format('woff2');font-weight:400}
    @font-face{font-family:'Google Sans';src:url('https://fokuscloud.com.br/assets/fonts/google/google-sans-400.woff2') format('woff2');font-weight:400}
    @font-face{font-family:'Google Sans';src:url('https://fokuscloud.com.br/assets/fonts/google/google-sans-700.woff2') format('woff2');font-weight:700}
    @media(max-width:600px){.mail-pad{padding-left:24px!important;padding-right:24px!important}.mail-title{font-size:36px!important}.mail-code{font-size:52px!important}.mail-meta{font-size:10px!important}}
  </style>
</head>
<body style="margin:0;padding:0;background:#f2eef4;color:#241c29;font-family:'Google Sans',Arial,sans-serif">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $preheader }}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f2eef4">
    <tr><td align="center" style="padding:32px 12px">
      <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#fff;border:1px solid #e6e0e9">
        <tr><td class="mail-pad" style="position:relative;overflow:hidden;padding:26px 42px 0;background:#2b173d;color:#fff">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
            <td style="position:relative;z-index:1;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;color:#fff">FOKUS <span style="color:#b5cfb9">LAW</span></td>
            <td align="right" aria-hidden="true" style="color:#ffffff0e;font-family:'Bebas Neue','Arial Narrow',Impact,sans-serif;font-size:112px;line-height:.75">02</td>
          </tr></table>
          <div style="padding:15px 0 18px;color:#b5cfb9;font-family:Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.8px">IDENTIDADE · ACESSO · SEGURANÇA</div>
        </td></tr>
        <tr><td class="mail-pad" style="padding:35px 44px 32px">
          <h1 class="mail-title" style="margin:0;color:#2b173d;font-family:'Bebas Neue','Arial Narrow',Impact,sans-serif;font-size:43px;font-weight:400;line-height:1.02;letter-spacing:.4px;text-transform:uppercase">{{ $title }}</h1>
          <p style="margin:15px 0 0;color:#625869;font-family:'Google Sans',Arial,sans-serif;font-size:14px;line-height:1.65">{{ $intro }}</p>

          @if($code)
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:26px 0 13px;background:#1b1028">
              <tr><td style="padding:18px 20px 17px">
                <div style="color:#b5cfb9;font-family:Arial,sans-serif;font-size:9px;font-weight:700;letter-spacing:1.9px">{{ $codeLabel ?? 'SEU CÓDIGO DE VERIFICAÇÃO' }}</div>
                <div class="mail-code" style="margin-top:3px;color:#fff;font-family:'Bebas Neue','Arial Narrow',Impact,sans-serif;font-size:62px;line-height:1;letter-spacing:12px">{{ $formattedCode }}</div>
              </td></tr>
            </table>
            @if($expiry)
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td class="mail-meta" style="color:#706675;font-family:Arial,sans-serif;font-size:11px">Expira em <strong style="color:#2b173d">{{ $expiry }}</strong></td>
                <td align="right" class="mail-meta" style="color:#706675;font-family:Arial,sans-serif;font-size:11px">Uso único · não compartilhe</td>
              </tr></table>
            @endif
          @endif

          @if($actionLabel && $actionUrl)
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0 14px"><tr><td bgcolor="#2b173d" style="background:#2b173d">
              <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 21px;color:#fff;font-family:Arial,sans-serif;font-size:13px;font-weight:700;text-decoration:none">{{ $actionLabel }}</a>
            </td></tr></table>
            <p style="margin:0;color:#706675;font-family:Arial,sans-serif;font-size:10px;line-height:1.6">Se o botão não funcionar, copie este endereço no navegador:<br><a href="{{ $actionUrl }}" style="color:#7352a5;word-break:break-all">{{ $actionUrl }}</a></p>
          @endif

          @if($securityTitle || $securityText)
            <p style="margin:23px 0 0;padding:14px 0 0;border-top:1px solid #eeeaf0;color:#625869;font-family:'Google Sans',Arial,sans-serif;font-size:12px;line-height:1.65">
              @if($securityTitle)<strong style="color:#2b173d">{{ $securityTitle }}</strong> @endif{{ $securityText }}
            </p>
          @endif

          @if(count($details))
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:25px"><tr>
              @foreach($details as $detail)
                <td valign="top" width="50%" style="padding:11px 10px 0 0;border-top:1px solid #e9e4eb">
                  <div style="color:#867c89;font-family:Arial,sans-serif;font-size:9px;letter-spacing:1.2px">{{ $detail['label'] }}</div>
                  <div style="margin-top:5px;color:#43364c;font-family:Arial,sans-serif;font-size:12px;font-weight:700">{{ $detail['value'] }}</div>
                </td>
              @endforeach
            </tr></table>
          @endif
        </td></tr>
        <tr><td class="mail-pad" style="padding:21px 42px 19px;background:#1b1028;color:#d3cbd7">
          <div style="color:#fff;font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:1.6px">FOKUS <span style="color:#a58bc8">LAW</span></div>
          <p style="margin:8px 0 12px;color:#c2b8c7;font-family:Arial,sans-serif;font-size:11px;line-height:1.6">{{ $footerMessage }}</p>
          <div style="color:#b5cfb9;font-family:Arial,sans-serif;font-size:10px;line-height:1.7">Gestão processual · Audiências · Expedições<br>Contatos · Tarefas e fluxos</div>
          <div style="margin-top:12px;padding-top:11px;border-top:1px solid #ffffff22;color:#aaa0af;font-family:Arial,sans-serif;font-size:9px;line-height:1.6">{{ $footerNotice }}</div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
