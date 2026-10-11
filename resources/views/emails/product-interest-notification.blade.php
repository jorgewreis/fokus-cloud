<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Novo interesse no Fokus Law</title>
</head>
<body style="margin:0;padding:24px;background:#f2eef4;color:#241c29;font-family:Arial,sans-serif">
    <main style="max-width:720px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e6e0e9">
        <p style="margin:0;color:#7352a5;font-size:12px;font-weight:700;letter-spacing:1.5px">FOKUS CLOUD · INTERESSE DE PRODUTO</p>
        <h1 style="margin:12px 0 24px;color:#2b173d;font-size:26px">Novo interesse no Fokus Law</h1>
        <p style="margin:0 0 20px;color:#625869;line-height:1.6">Um visitante enviou uma sugestão sobre módulos, funcionalidades e necessidades da operação.</p>
        <table role="presentation" style="width:100%;border-collapse:collapse">
            <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb;width:190px">Nome</th><td style="padding:10px;border-top:1px solid #e9e4eb">{{ $interest->name }}</td></tr>
            <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb">E-mail</th><td style="padding:10px;border-top:1px solid #e9e4eb"><a href="mailto:{{ $interest->email }}">{{ $interest->email }}</a></td></tr>
            <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb">Organização</th><td style="padding:10px;border-top:1px solid #e9e4eb">{{ $interest->organization ?: 'Não informada' }}</td></tr>
            <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb">Segmento</th><td style="padding:10px;border-top:1px solid #e9e4eb">{{ implode(', ', $interest->profiles ?? []) ?: 'Não informado' }}</td></tr>
            <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb">Módulos de interesse</th><td style="padding:10px;border-top:1px solid #e9e4eb">{{ implode(', ', $interest->modules ?? []) ?: 'Não informado' }}</td></tr>
            <tr><th align="left" valign="top" style="padding:10px;border-top:1px solid #e9e4eb">Problema relatado</th><td style="padding:10px;border-top:1px solid #e9e4eb;white-space:pre-wrap">{{ $interest->main_difficulties }}</td></tr>
            @if($interest->current_process)
                <tr><th align="left" valign="top" style="padding:10px;border-top:1px solid #e9e4eb">Prioridades e módulos desejados</th><td style="padding:10px;border-top:1px solid #e9e4eb;white-space:pre-wrap">{{ $interest->current_process }}</td></tr>
            @endif
            @if($interest->source_url)
                <tr><th align="left" style="padding:10px;border-top:1px solid #e9e4eb">Página de origem</th><td style="padding:10px;border-top:1px solid #e9e4eb"><a href="{{ $interest->source_url }}">{{ $interest->source_url }}</a></td></tr>
            @endif
        </table>
        <p style="margin:24px 0 0;color:#7b7181;font-size:12px">Os dados do interesse também foram registrados no Backoffice.</p>
    </main>
</body>
</html>
