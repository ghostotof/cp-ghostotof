import { describe, expect, it } from 'vitest'
import {
  MCP_SERVER_LOCAL_NAME,
  claudeCodeCommand,
  mcpServerUrl,
  mcpServersJson,
} from '../../../../src/domain/mcp/services/mcpClientConfiguration'

describe('mcpServerUrl', () => {
  it("ajoute le chemin du serveur MCP à l'origine reçue", () => {
    expect(mcpServerUrl('https://site.example.test')).toBe('https://site.example.test/api/mcp')
  })

  /**
   * L'origine d'une préprod ou d'un poste de dev porte un port : il doit
   * survivre, sans quoi le client se brancherait sur un autre serveur.
   */
  it("conserve le port de l'origine", () => {
    expect(mcpServerUrl('http://localhost:8080')).toBe('http://localhost:8080/api/mcp')
  })
})

describe('claudeCodeCommand', () => {
  it('ajoute le serveur distant en transport HTTP, sous le nom du serveur', () => {
    expect(claudeCodeCommand('https://site.example.test/api/mcp')).toBe(
      `claude mcp add --transport http ${MCP_SERVER_LOCAL_NAME} https://site.example.test/api/mcp`,
    )
  })
})

describe('mcpServersJson', () => {
  /**
   * Cursor et l'AI Assistant de PhpStorm lisent le même schéma
   * (`mcpServers` → nom → `url`) : un serveur distant n'y a besoin que de son
   * URL, le transport Streamable HTTP étant déduit de la présence d'`url`.
   */
  it('décrit le serveur par son seul URL sous mcpServers, en JSON valide', () => {
    const json = mcpServersJson('https://site.example.test/api/mcp')

    expect(JSON.parse(json)).toEqual({
      mcpServers: { [MCP_SERVER_LOCAL_NAME]: { url: 'https://site.example.test/api/mcp' } },
    })
  })

  it('indente le JSON pour être lu et collé tel quel', () => {
    expect(mcpServersJson('https://site.example.test/api/mcp')).toContain('\n  "mcpServers": {')
  })
})
