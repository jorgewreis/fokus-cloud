# REI-24: evidências da implementação isolada

Data: 09/11/2026. Base: `8dd9835053b7dc986343e8cfc44b5c35bdde920a`.
Branch: `feat/rei24-immutable-release-tooling-20261109`.
Escopo: [primitivas B+C](immutable-release-tooling.md), não implantação.

## Resultados locais

| Validação | Resultado | Alcance / limite |
| --- | --- | --- |
| `PYTHONDONTWRITEBYTECODE=1 python3 tools/releases/test_immutable.py -q` | 46 testes, exit 0 | Git/filesystem/locks/subprocessos sintéticos; selo #59 real |
| Mesmo conjunto em checkout de composição #60 | 46 testes, exit 0 | Código copiado só no checkout descartável; nenhuma branch integrada |
| `python3 -m unittest discover -s tests/Deployment -p 'test_*.py' -q` na composição | 78 testes, exit 0 | Contratos #55–58 preservados; includes guard e regressões de workflow |
| `python3 tools/ci/test-prepare-release.py -q` na composição | 6 testes, exit 0 | Preparador #59 original preservado |
| `npm ci --ignore-scripts --cache <cache-local-descartavel>` na main exata | exit 0 | Lock respeitado, nenhuma dependência atualizada |
| `npm run build` na main exata | exit 0 | Vite real, Node 24.22.1 / npm 11.9.0 |
| Empacotar essa árvore após build real | exit 1, `dirty_source` | Recusa correta, cinco HTML rastreados alterados pelo build |
| PHP/Composer/FPM local | Não executado | Binários PHP/Composer ausentes; nenhum mock contado como runtime real |
| VPS, migration, merge, deploy, permissões/cron/secrets produtivos | Não executado | Fora da autorização desta etapa |

A primeira tentativa npm usou cache padrão indisponível e falhou com ENOENT;
nenhum build foi contabilizado nessa tentativa. Com cache explicitamente local ao
executor e worktree descartável dedicado, instalação e build reais passaram.
Não se atribui essa falha à aplicação ou a uma vulnerabilidade das dependências.

Locks conferidos no build:

- composer.lock SHA256: `161985820ff5dc74365e981e22b07744b46ebca0b0ee81e2600cc8ae994fda69`.
- package-lock.json SHA256: `968f24dfd9ae39a959f82a075b1bf60d4a0e63a653a8bf3bba7daf88a7b49584`.

Os cinco arquivos reescritos são public/styles/docs/{components,forms,helpers,
layout,utilities}/index.html. A ferramenta não normalizou o diff nem substituiu o
SHA. O teste sintético específico também recusa config/fokus-law-assets.json, em
vez de permitir um executável/configuração ignorado por conveniência.

## Cobertura das novas primitivas

- Empacotamento determinístico, extração exata e digest divergente.
- Fonte alterada, SHA errado, destino existente, symlink e traversal recusados.
- Vendor externo, extras duplicados/PHP não aprovado e manifest Law recusados.
- .env não copiado; link compartilhado preservado e alvo existente não substituído.
- Bytes do artefato conferidos novamente antes do selo; caches locais exigidos.
- Selo real #59, identidade vinculada, receipt sem identidade e links de código recusados.
- Integridade alterada, diretório gravável e raiz movida recusados.
- Modos privados de cache preservados, sem tornar segredo world-readable.
- Política não configurada/expirada/outro alvo/receipt, runtime root e mesma identidade recusados.
- OPcache/preload desconhecidos/ativos e verificador inexistente/URL inválida bloqueiam antes do switch.
- Ativação positiva sintética preserva anterior; falha posterior deixa latch e não anuncia sucesso.
- Contenção de locks impede switch; rollback exige compatibilidade explícita de dados/código.
- Scheduler resolve uma geração, usa receipt/evidência correspondentes e PHP explícito.
- Falha de subprocesso conserva latch; timeout foi inspecionado, não ensaiado; runInBackground conhecido é recusado.
- Erros operacionais e argumentos inválidos não ecoam canários de caminhos/segredos.

A máquina de estados usa **injeção de resultado do verificador** nos testes de
switch; não alegamos HTTP/FPM testado por isso. O preflight do verificador #57 e
o preparador #59 são subprocessos reais com código fixado. Os fixtures de PHP
são bytes sintéticos, não execução do engine PHP. As simulações de scheduler usam
um executável de teste e não acessam serviços ou banco.

## Composição e concorrência

PRs reconsultados antes da implementação: #55 `71e31abb`, #56 `8de6de49`,
#57 `a8cc9729`, #58 `485fe638`, #59 `b271260d`, todos abertos/draft. Main não mudou.
O checkout de ensaio #60 `5ddd7eff54848f81d91d6a311d0bffabeff22acb` documenta
exatamente esses heads; foi reutilizado como teste, não adotado como base nem
integrado. A nova branch parte da main e adiciona somente seus próprios arquivos.

Nenhum arquivo original de #55–59 foi alterado; deploy.yml permanece intacto.
Os testes de contrato #58 continuam aceitando só os deltas revisados. Novos scripts,
testes e workflow mudam a superfície operacional e **invalidam a dispensa do #55
até reauditoria explícita**. AUDIT_DIGEST não foi atualizado. Preservar checks não
significa que a nova integração está liberada para publicação.

Revisão independente do código encontrou e motivou correções em concorrência,
latch pós-falha, preflight, vínculo de política, inventário, fotografia do tar,
bootstrap manifests e preservação de permissões privadas. Regressões foram
adicionadas. Isso é revisão/teste deste escopo, não certificação geral.

## Gates ainda abertos

1. Build idempotente e proveniência do manifest Law compatíveis com #59.
2. Drenagem de descendentes/scheduler real e nenhum cron legado concorrente.
3. PHP/FPM efetivos, extensões, preload/OPcache, FastCGI, ACL e leitura pelo runtime.
4. Bootstrap inicial de current/vhost/endpoint, gate produtivo e transporte aprovado.
5. Backup/restore integral, schema N/N-1, shared drivers, assets antigos e orçamento.
6. Boot Laravel real da candidata selada e verificação HTTPS/FPM, não realizados aqui.

Não há rollout automático que ignore esses gates. A implementação é uma entrega
isolada verificável e parcial para revisão; produção e a integração FEKS continuam
pendentes de requisitos reais e autorizações próprias.

A separação do scheduler exige UID/GID de runtime diferente do controlador e troca explícita de principal, sem fallback. O teste de sucesso injeta um lançador sintético; troca real de UID e acesso Laravel continuam não comprovados neste executor.

## Revisão da falha remota e recuperação (09/11/2026)

A execução [42602762301](https://github.com/jorgewreis/fokus-cloud/actions/runs/42602762301/job/129111004118), head 16efd7d5, reprovou 1/46 testes. Os logs completos confirmam a linha `next(glob('activation-*.json'))`: o padrão inclui o latch `activation-blocked.json`, cujo estado conservador é `switch_intent`, e o journal UUID com `activation_unverified_manual_recovery_required`. Ordem de diretório não é contrato. A reprodução força o latch como primeiro resultado e demonstra a antiga asserção incorreta; nenhuma transição de sucesso ou rollback automático ocorreu.

Correção: selecionar exatamente o journal com 32 dígitos hexadecimais, conferir seu estado e o latch separadamente. Preservados release anterior e bloqueio de scheduler/novas ativações. Adicionado fsync do diretório após troca de current e remoção de latch para explicitar durabilidade desses pontos.

A revisão executou **54 testes**, incluindo subprocesso real com timeout e quatro filhos que encerram abruptamente com os._exit após latch, journal, troca ou journal verificado. Journal truncado mantém bloqueio; retomada exige política explícita compatível. São testes de interrupção de processo/filesystem, não simulação de queda elétrica nem ensaio PHP/FPM.

Build real repetido: 393 arquivos public/config idênticos; empacotador recusa a árvore suja. A causa é cache-busting que reescreve cinco HTML canônicos, não aleatoriedade observada. Manifesto Law e 14 assets validados; rsync ausente, essa parte não executada. Solução afeta contrato de build/identidade e fica separada na [proposta REI-25](immutable-release-build-dependency.md), sem ampliar allowlist.

O background Law permanece intacto e bloqueado. A [proposta REI-26](immutable-release-scheduler-dependency.md) define supervisão por geração e resultados individuais; não confunde saída do pai com drenagem/sucesso de filhos. Nenhum suporte cgroup na VPS é alegado.

Resultado de CI remoto do novo head deve ser consultado no PR/Linear, não inferido pelos testes locais.
