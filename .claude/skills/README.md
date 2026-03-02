# JAWS Project Skills

This directory contains Claude Code skills for common development workflows in the JAWS project.

## Available Skills

### 🧪 Testing & Quality

#### `test` - Test Runner
Run PHPUnit tests with appropriate commands.
- All tests, unit tests, integration tests, API tests
- Specific test files
- Verbose output options

#### `troubleshooting` - Common Issues & Solutions
Diagnose and fix common development issues.
- Database errors (locked, permissions, corruption)
- Test failures (unit, integration, API)
- Server issues (Apache, 404s, 500s)
- Authentication problems
- Performance issues

### 📝 Development Workflows

#### `conventional-commits` - Commit Message Guide
Create properly formatted commit messages following Conventional Commits specification.
- Commit types and rules
- Examples and templates
- Multi-line commit formatting

#### `add-endpoint` - Add API Endpoint
Step-by-step guide for adding new REST API endpoints.
- Use case creation
- Request/Response DTOs
- Controller implementation
- Route configuration
- Testing

#### `modify-schema` - Database Schema Modifications
Safely modify database schema using Phinx migrations.
- Creating migrations
- Updating entities and repositories
- Testing changes
- Production deployment

### 🚀 Operations

#### `database-ops` - Database Operations
Common database operations using SQLite and Phinx.
- Apply migrations
- Backup and restore
- Query database
- Download from production
- Database maintenance

#### `deploy-lightsail` - AWS Lightsail Deployment
Deploy the JAWS application to AWS Lightsail production.
- SFTP file upload
- Permission configuration
- Database migrations
- Verification steps
- Rollback procedures

### ⚙️ Advanced Development

#### `add-ranking` - Add Ranking Criteria
Add new ranking dimensions to boat/crew ranking system.
- Enum configuration
- Entity updates
- RankingService implementation
- Testing and validation

#### `add-rule` - Add Assignment Rule
Add new optimization rules to the crew-to-boat assignment algorithm.
- Rule implementation
- Loss and gradient calculations
- Priority ordering
- Testing strategies

## How to Use Skills

Skills are invoked using the `/` prefix in Claude Code:

```
/test
/troubleshooting
/conventional-commits
/add-endpoint
/modify-schema
/database-ops
/deploy-lightsail
/add-ranking
/add-rule
```

Each skill provides:
- **Context-specific guidance** for the task
- **Step-by-step instructions** with examples
- **Code templates** for common patterns
- **Checklists** to ensure nothing is missed
- **Best practices** and important considerations

## Skill Categories

### Frequently Used
- `/test` - Run tests during development
- `/troubleshooting` - Fix common issues
- `/conventional-commits` - Create proper commit messages

### Common Development
- `/add-endpoint` - Add new API features
- `/modify-schema` - Database schema changes

### Operations & Deployment
- `/database-ops` - Database maintenance
- `/deploy-lightsail` - Production deployment

### Specialized Development
- `/add-ranking` - Modify ranking algorithm
- `/add-rule` - Modify assignment algorithm

## Benefits of Using Skills

1. **Consistency**: Follow established patterns and conventions
2. **Efficiency**: Save time with step-by-step guides
3. **Quality**: Built-in checklists ensure completeness
4. **Safety**: Warnings and rollback procedures for risky operations
5. **Learning**: Understand the "why" behind each step

## Adding New Skills

To add a new skill:

1. Create a new `.md` file in `.claude/skills/`
2. Follow the structure of existing skills
3. Include clear examples and checklists
4. Update this README with the new skill

## Skill Structure

Each skill typically includes:

- **Overview**: What the skill does
- **Prerequisites**: What's needed before starting
- **Step-by-step guide**: Detailed instructions
- **Examples**: Real-world code examples
- **Checklist**: Verification steps
- **Troubleshooting**: Common issues and solutions
- **References**: Links to relevant files

## Related Documentation

For comprehensive documentation, see:

- **[CLAUDE.md](../../CLAUDE.md)** - AI assistant guidance (technical specs)
- **[README.md](../../README.md)** - Project overview
- **[docs/](../../docs/)** - Human-readable documentation
  - SETUP.md - Installation and setup
  - DEVELOPER_GUIDE.md - Architecture and best practices
  - API.md - API endpoint documentation
  - DEPLOYMENT.md - Deployment procedures

## Contributing

When updating skills:

1. Keep instructions clear and concise
2. Provide working code examples
3. Include error handling guidance
4. Test instructions before committing
5. Update this README if adding/removing skills

## Feedback

If you find issues with skills or have suggestions for improvement, please discuss with the team or create an issue in the project repository.
